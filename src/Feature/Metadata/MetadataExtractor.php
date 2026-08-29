<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpClassReference;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpTypedVariable;
use Symfony\Lsp\Parser\Php\PhpTypedVariableKind;

final class MetadataExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly YamlConfigurationParser $yaml,
        private readonly PhpParserInterface $phpParser,
        private readonly PhpCommentParserInterface $phpComments,
        private readonly BalancedDelimiterMatcher $delimiters,
    ) {
    }

    public function extract(string $uri, string $languageId, string $text): MetadataSourceFacts
    {
        if ('php' === $languageId) {
            $php = $this->phpParser->parse($text);
            $source = $this->phpComments->mask($text);
            $formDataClasses = $this->formDataClasses($source, $php);

            return new MetadataSourceFacts(
                $uri,
                $this->unique($this->phpSymbols($uri, $text, $source, $php, $formDataClasses)),
                $formDataClasses,
            );
        }

        return new MetadataSourceFacts($uri, 'yaml' === $languageId ? $this->unique($this->yamlSymbols($uri, $text)) : []);
    }

    public function completionContext(string $languageId, string $text, int $offset): ?MetadataCompletionContext
    {
        return match ($languageId) {
            'php' => $this->phpCompletionContext($text, $offset),
            'yaml' => $this->yamlCompletionContext($text, $offset),
            default => null,
        };
    }

    /**
     * @return list<array{class: string, option: string, range: Range}>
     */
    public function formOptions(string $text): array
    {
        $php = $this->phpParser->parse($text);
        $options = [];
        foreach ($this->formCalls($php) as $call) {
            $typeIndex = 'createNamed' === $call->method() ? 1 : ('add' === $call->method() ? 1 : 0);
            $optionsIndex = 'createNamed' === $call->method() ? 3 : 2;
            $type = $this->classReferenceArgument($php, $call->argument($typeIndex));
            $argument = $call->argument($optionsIndex);
            if (null === $type || null === $argument) {
                continue;
            }
            foreach ($this->arrayKeys($text, $argument) as $key) {
                $options[] = ['class' => $type->className(), 'option' => $key['name'], 'range' => $key['range']];
            }
        }

        return $options;
    }

    /** @return list<array{constraint: string, option: string, range: Range}> */
    public function constraintOptions(string $text): array
    {
        $php = $this->phpParser->parse($text);
        $options = [];
        foreach ($php->attributes() as $attribute) {
            if (array_any($attribute->arguments(), static fn (PhpArgument $argument): bool => str_contains((string) $argument->expression(), 'new '))) {
                continue;
            }
            foreach ($attribute->arguments() as $argument) {
                $name = $argument->name();
                if (null === $name) {
                    continue;
                }
                $prefixEnd = $argument->expressionStartOffset() ?? $argument->endOffset();
                $prefix = substr($text, $argument->startOffset(), $prefixEnd - $argument->startOffset());
                $relativeOffset = strpos($prefix, $name);
                if (false === $relativeOffset) {
                    continue;
                }
                $absolute = $argument->startOffset() + $relativeOffset;
                $options[] = ['constraint' => $attribute->name(), 'option' => $name, 'range' => $this->converter->toRange($text, $absolute, \strlen($name))];
            }
        }

        return $options;
    }

    /** @return list<array{constraint: string, option: string, range: Range}> */
    public function yamlConstraintOptions(string $text): array
    {
        $options = [];
        foreach ($this->yaml->parse($text) as $occurrence) {
            $path = $occurrence->path();
            $count = \count($path);
            if ($count < 5 || 'properties' !== $path[1]) {
                continue;
            }
            $options[] = [
                'constraint' => $path[$count - 2],
                'option' => $path[$count - 1],
                'range' => $occurrence->keyRange(),
            ];
        }

        return $options;
    }

    /** @return list<FormDataClass> */
    private function formDataClasses(string $source, PhpDocument $php): array
    {
        $classes = [];
        foreach ($php->methodDeclarations() as $method) {
            if ('configureOptions' !== $method->name()
                || 'Symfony\\Component\\OptionsResolver\\OptionsResolver' !== $method->firstParameterType()
                || null === ($resolver = $this->typedMethodParameter($php, $method->className(), $method->name(), 'Symfony\\Component\\OptionsResolver\\OptionsResolver'))
            ) {
                continue;
            }
            $dataClass = null;
            foreach ($php->methodCalls() as $call) {
                if (!\in_array($call->method(), ['setDefaults', 'setDefault'], true)
                    || $method->className() !== $call->className()
                    || $method->name() !== $call->enclosingMethod()
                    || $resolver->scopeStartOffset() !== $call->scopeStartOffset()
                    || PhpMethodReceiverKind::Variable !== $call->receiverContext()->kind()
                    || $resolver->name() !== $call->receiverContext()->name()
                ) {
                    continue;
                }
                if ('setDefaults' === $call->method()) {
                    $argument = $call->argument(0);
                    $expression = $argument?->expression();
                    $offset = $argument?->expressionStartOffset();
                    if (!\is_string($expression) || !\is_int($offset) || null === $entries = $this->arrayEntries($expression, $offset)) {
                        $dataClass = null;
                        continue;
                    }
                    if (!\array_key_exists('data_class', $entries)) {
                        continue;
                    }
                    $dataClassExpression = $entries['data_class'];
                } else {
                    if ('data_class' !== $this->quotedIdentifier($call->argument(0)?->expression() ?? '')) {
                        continue;
                    }
                    $argument = $call->argument(1);
                    $expression = $argument?->expression();
                    $offset = $argument?->expressionStartOffset();
                    if (!\is_string($expression) || !\is_int($offset)) {
                        $dataClass = null;
                        continue;
                    }
                    $dataClassExpression = ['text' => $expression, 'offset' => $offset];
                }
                $dataClass = $this->staticClassName($source, $dataClassExpression, $php);
            }
            if (null !== $dataClass) {
                $classes[strtolower(ltrim($method->className(), '\\'))] = new FormDataClass($method->className(), $dataClass);
            }
        }

        return array_values($classes);
    }

    /**
     * @param list<FormDataClass> $formDataClasses
     *
     * @return list<MetadataSourceSymbol>
     */
    private function phpSymbols(string $uri, string $text, string $source, PhpDocument $php, array $formDataClasses): array
    {
        $symbols = [];
        foreach ($php->typeDeclarations() as $type) {
            if (!$type->isClass()) {
                continue;
            }
            $range = $this->converter->toRange($text, $type->nameStartOffset(), $type->nameEndOffset() - $type->nameStartOffset());
            $symbols[] = new MetadataSourceSymbol(
                MetadataSymbolKind::MappedClass,
                $type->name(),
                $uri,
                $range,
                true,
            );
            if ('Symfony\\Component\\Validator\\Constraint' === $type->parentClassName()) {
                $separator = strrpos($type->name(), '\\');
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::Constraint,
                    false === $separator ? $type->name() : substr($type->name(), $separator + 1),
                    $uri,
                    $range,
                    true,
                );
            }
        }
        foreach ($php->propertyDeclarations() as $property) {
            $symbols[] = new MetadataSourceSymbol(
                MetadataSymbolKind::Property,
                $property->className().'::$'.$property->name(),
                $uri,
                $this->converter->toRange($text, $property->nameStartOffset(), $property->nameEndOffset() - $property->nameStartOffset()),
                true,
                $property->signature(),
                $property->description(),
            );
        }

        $dataClasses = [];
        foreach ($formDataClasses as $formDataClass) {
            $dataClasses[strtolower(ltrim($formDataClass->formClass(), '\\'))] = $formDataClass->dataClass();
        }
        foreach ($php->methodDeclarations() as $method) {
            $dataClass = $dataClasses[strtolower(ltrim($method->className(), '\\'))] ?? null;
            if (null === $dataClass
                || 'buildForm' !== $method->name()
                || 'Symfony\\Component\\Form\\FormBuilderInterface' !== $method->firstParameterType()
                || null === ($builder = $this->typedMethodParameter($php, $method->className(), $method->name(), 'Symfony\\Component\\Form\\FormBuilderInterface'))
            ) {
                continue;
            }
            foreach ($php->methodCalls() as $call) {
                if ('add' !== $call->method()
                    || $method->className() !== $call->className()
                    || $method->name() !== $call->enclosingMethod()
                    || $builder->scopeStartOffset() !== $call->scopeStartOffset()
                    || !$this->isDirectFormBuilderReceiver($call->receiver(), $builder->name())
                ) {
                    continue;
                }
                $field = null === $call->argument(0) ? null : $this->quotedIdentifierArgument($text, $call->argument(0));
                $property = null === $field ? null : $this->formPropertyName($call->arguments(), $field['name']);
                if (null === $field || null === $property) {
                    continue;
                }
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::Property,
                    $dataClass.'::$'.$property,
                    $uri,
                    $field['range'],
                    false,
                );
            }
        }

        foreach ($php->attributes() as $attribute) {
            if (\in_array($attribute->name(), [
                'Symfony\\Component\\Serializer\\Attribute\\Groups',
                'Symfony\\Component\\Serializer\\Annotation\\Groups',
            ], true)) {
                foreach ($attribute->arguments() as $argument) {
                    $expression = $argument->expression();
                    $offset = $argument->expressionStartOffset();
                    if (\is_string($expression) && \is_int($offset)) {
                        array_push($symbols, ...$this->quotedSymbols(MetadataSymbolKind::SerializerGroup, $uri, $text, $expression, $offset, true));
                    }
                }
            }
        }
        preg_match_all('/["\']groups["\']\s*=>\s*\[(.*?)\]/s', $source, $groupReferences, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($groupReferences as $group) {
            array_push($symbols, ...$this->quotedSymbols(MetadataSymbolKind::SerializerGroup, $uri, $text, $group[1][0], $group[1][1], false));
        }
        $constraintNamespace = 'Symfony\\Component\\Validator\\Constraints\\';
        foreach ($php->attributes() as $attribute) {
            $className = $attribute->name();
            $rawName = substr($text, $attribute->nameStartOffset(), $attribute->nameEndOffset() - $attribute->nameStartOffset());
            if (str_starts_with($className, $constraintNamespace)) {
                $name = substr($className, \strlen($constraintNamespace));
                if (str_contains($name, '\\')) {
                    continue;
                }
                $segmentOffset = (int) strrpos('\\'.$rawName, '\\');
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::Constraint,
                    $name,
                    $uri,
                    $this->converter->toRange($text, $attribute->nameStartOffset() + $segmentOffset, \strlen($rawName) - $segmentOffset),
                    false,
                );

                continue;
            }
            $aliasOffset = '\\' === ($rawName[0] ?? '') ? 1 : 0;
            $separator = strpos($rawName, '\\', $aliasOffset);
            $alias = substr($rawName, $aliasOffset, false === $separator ? null : $separator - $aliasOffset);
            $imported = $php->imports()[$alias] ?? null;
            if (!\is_string($imported)
                || (!str_contains($imported, '\\Validator\\') && !str_contains($imported, '\\Constraints\\'))
            ) {
                continue;
            }
            $symbols[] = new MetadataSourceSymbol(
                MetadataSymbolKind::Constraint,
                $alias,
                $uri,
                $this->converter->toRange($text, $attribute->nameStartOffset() + $aliasOffset, \strlen($alias)),
                false,
            );
        }

        return $symbols;
    }

    /** @return list<MetadataSourceSymbol> */
    private function yamlSymbols(string $uri, string $text): array
    {
        $symbols = [];
        foreach ($this->yaml->parse($text) as $occurrence) {
            $path = $occurrence->path();
            if (1 === \count($path) && str_contains($path[0], '\\')) {
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::MappedClass,
                    $path[0],
                    $uri,
                    $occurrence->keyRange(),
                    false,
                );
            }
            if (3 === \count($path) && \in_array($path[1], ['properties', 'attributes'], true)) {
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::Property,
                    $path[0].'::$'.$path[2],
                    $uri,
                    $occurrence->keyRange(),
                    false,
                );
            }
            if (4 === \count($path) && 'properties' === $path[1]) {
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::Constraint,
                    $path[3],
                    $uri,
                    $occurrence->keyRange(),
                    false,
                );
            }
            if ([] === $path || 'groups' !== $path[array_key_last($path)]) {
                continue;
            }
            $start = $this->converter->toByteOffset($text, $occurrence->valueRange()->start());
            $end = $this->converter->toByteOffset($text, $occurrence->valueRange()->end());
            $value = substr($text, $start, $end - $start);
            preg_match_all('/[A-Za-z_][A-Za-z0-9_.:-]*/', $value, $names, \PREG_OFFSET_CAPTURE);
            foreach ($names[0] as [$name, $offset]) {
                $symbols[] = new MetadataSourceSymbol(MetadataSymbolKind::SerializerGroup, $name, $uri, $this->converter->toRange($text, $start + $offset, \strlen($name)), true);
            }
        }

        return $symbols;
    }

    private function phpCompletionContext(string $text, int $offset): ?MetadataCompletionContext
    {
        $before = substr($this->phpComments->mask($text), 0, $offset);
        if (preg_match('/(?:["\']groups["\']\s*=>\s*\[[^\]]*|(?:[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\\\\)?Groups\s*\([^\)]*)["\']([A-Za-z_][A-Za-z0-9_.:-]*)$/s', $before, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::SerializerGroup, $match[1][0], $text, $match[1][1]);
        }
        $attribute = strrpos($before, '#[');
        if (false !== $attribute && !str_contains(substr($before, $attribute), ']')) {
            $php = $this->phpParser->parse($text);
            $expression = substr($before, $attribute + 2);
            if (preg_match('/^\s*([\\\\A-Za-z_][A-Za-z0-9_\\\\]*)\s*\((.*)$/s', $expression, $constraint) && preg_match('/(?:^|,)\s*([A-Za-z_][A-Za-z0-9_]*)$/', $constraint[2], $option, \PREG_OFFSET_CAPTURE)) {
                $optionOffset = $attribute + 2 + strpos($expression, $constraint[2]) + $option[1][1];

                return $this->context(MetadataCompletionKind::ConstraintOption, $option[1][0], $text, $optionOffset, $php->resolveName($constraint[1]));
            }
            if (preg_match('/^\s*([\\\\A-Za-z_][A-Za-z0-9_\\\\]*)$/', $expression, $constraint, \PREG_OFFSET_CAPTURE)) {
                $name = $constraint[1][0];
                $separator = strrpos($name, '\\');
                if (false !== $separator) {
                    $class = $php->resolveName(substr($name, 0, $separator + 1).'Constraint');
                    $name = substr($name, $separator + 1);
                    if (str_starts_with($class, 'Symfony\\Component\\Validator\\Constraints\\')) {
                        $nameOffset = $attribute + 2 + $constraint[1][1] + $separator + 1;

                        return $this->context(MetadataCompletionKind::Constraint, $name, $text, $nameOffset);
                    }
                } else {
                    $candidates = [];
                    foreach ($php->imports() as $alias => $class) {
                        if (str_starts_with($alias, $name)
                            && str_starts_with($class, 'Symfony\\Component\\Validator\\Constraints\\')
                            && !str_contains(substr($class, \strlen('Symfony\\Component\\Validator\\Constraints\\')), '\\')
                        ) {
                            $candidates[] = ['label' => $alias, 'class' => $class];
                        }
                    }
                    if ([] !== $candidates) {
                        return $this->context(MetadataCompletionKind::Constraint, $name, $text, $attribute + 2 + $constraint[1][1], candidates: $candidates);
                    }
                }
            }
        }

        return $this->formCompletionContext($text, $offset);
    }

    private function formCompletionContext(string $text, int $offset): ?MetadataCompletionContext
    {
        $php = $this->phpParser->parse($text);
        $formBuilders = $this->formBuilderVariables($php);
        $masked = $this->phpComments->mask($text);
        preg_match_all('/(?:(->)\s*)?\b(createForm|createNamed|add)\s*\(/', substr($masked, 0, $offset), $calls, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach (array_reverse($calls) as $call) {
            if ('add' === $call[2][0] && null === $this->directFormBuilderVariable($masked, $call[0][1], $formBuilders)) {
                continue;
            }
            $open = $call[0][1] + \strlen($call[0][0]) - 1;
            $close = $this->delimiters->matching($masked, $open, '(', ')');
            if (null !== $close && $close < $offset) {
                continue;
            }
            $arguments = $this->arguments(substr($masked, $open + 1, $offset - $open - 1), $open + 1);
            $name = $call[2][0];
            if ('add' === $name && 0 === \count($arguments) - 1
                && preg_match('/^\\s*["\']([A-Za-z_][A-Za-z0-9_]*)?$/', $arguments[0]['text'], $prefix, \PREG_OFFSET_CAPTURE)
                && null !== $class = $this->enclosingClass($php, $call[0][1])
            ) {
                $value = isset($prefix[1]) ? $prefix[1][0] : '';

                return $this->context(MetadataCompletionKind::FormProperty, $value, $text, $offset - \strlen($value), $class);
            }
            $typeIndex = 'createNamed' === $name ? 1 : ('add' === $name ? 1 : 0);
            $optionsIndex = 'createNamed' === $name ? 3 : 2;
            if (\count($arguments) - 1 !== $optionsIndex || !isset($arguments[$typeIndex])) {
                continue;
            }
            $current = $arguments[$optionsIndex];
            if (!preg_match('/^\s*\[/', $current['text']) || !preg_match('/["\']([A-Za-z_][A-Za-z0-9_]*)$/', $current['text'], $prefix, \PREG_OFFSET_CAPTURE)) {
                continue;
            }
            if (!preg_match('/^\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::class\b/', $arguments[$typeIndex]['text'], $type)) {
                continue;
            }
            $class = $php->resolveName($type[1]);
            $prefixOffset = $current['offset'] + $prefix[1][1];

            return $this->context(MetadataCompletionKind::FormOption, $prefix[1][0], $text, $prefixOffset, $class);
        }

        return null;
    }

    private function yamlCompletionContext(string $text, int $offset): ?MetadataCompletionContext
    {
        $before = substr($text, 0, $offset);
        $lineOffset = strrpos($before, "\n");
        $lineOffset = false === $lineOffset ? 0 : $lineOffset + 1;
        $line = substr($before, $lineOffset);
        $parent = $this->yaml->parentPath($text, $lineOffset);
        if (2 === \count($parent) && \in_array($parent[1], ['properties', 'attributes'], true) && preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::Property, $match[1][0], $text, $lineOffset + $match[1][1], $parent[0]);
        }
        if (\count($parent) >= 4 && 'properties' === $parent[1] && preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::ConstraintOption, $match[1][0], $text, $lineOffset + $match[1][1], $parent[array_key_last($parent)]);
        }
        if (\count($parent) >= 3 && 'properties' === $parent[1] && preg_match('/^\s*-\s*([A-Za-z_][A-Za-z0-9_]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::Constraint, $match[1][0], $text, $lineOffset + $match[1][1]);
        }
        if ([] !== $parent && 'groups' === $parent[array_key_last($parent)] && preg_match('/^\s*-\s*["\']?([A-Za-z_][A-Za-z0-9_.:-]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::SerializerGroup, $match[1][0], $text, $lineOffset + $match[1][1]);
        }
        if (preg_match('/\bgroups\s*:\s*\[[^\]]*["\']?([A-Za-z_][A-Za-z0-9_.:-]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::SerializerGroup, $match[1][0], $text, $lineOffset + $match[1][1]);
        }

        return null;
    }

    /** @return list<PhpMethodCall> */
    private function formCalls(PhpDocument $php): array
    {
        $calls = [];
        foreach ($php->methodCalls() as $call) {
            if (!\in_array($call->method(), ['createForm', 'createNamed', 'add'], true)) {
                continue;
            }
            if ('add' === $call->method() && null === $this->formBuilderVariableForCall($php, $call)) {
                continue;
            }
            $calls[] = $call;
        }

        return $calls;
    }

    private function formBuilderVariableForCall(PhpDocument $php, PhpMethodCall $call): ?PhpTypedVariable
    {
        foreach ($php->typedVariables() as $variable) {
            if (PhpTypedVariableKind::Parameter !== $variable->kind()
                || !\in_array('Symfony\\Component\\Form\\FormBuilderInterface', $variable->types(), true)
                || $call->className() !== $variable->className()
                || $call->enclosingMethod() !== $variable->methodName()
                || $call->scopeStartOffset() !== $variable->scopeStartOffset()
                || 1 !== preg_match('/^\s*\\$'.preg_quote($variable->name(), '/').'\b/', $call->receiver())
            ) {
                continue;
            }

            return $variable;
        }

        return null;
    }

    /** @param array<string, true> $variables */
    private function directFormBuilderVariable(string $text, int $offset, array $variables): ?string
    {
        foreach (array_keys($variables) as $variable) {
            if ($this->isDirectFormBuilderCall($text, $offset, $variable)) {
                return $variable;
            }
        }

        return null;
    }

    private function isDirectFormBuilderCall(string $text, int $offset, string $variable): bool
    {
        $before = substr($text, 0, $offset);
        $statementStart = max((int) strrpos($before, ';'), (int) strrpos($before, '{'));

        return $this->isDirectFormBuilderReceiver(ltrim(substr($before, $statementStart), " \t\r\n;{"), $variable);
    }

    private function isDirectFormBuilderReceiver(string $receiver, string $variable): bool
    {
        $builder = '$'.$variable;
        if (!str_starts_with($receiver, $builder)) {
            return false;
        }
        $chain = substr($receiver, \strlen($builder));
        while ('' !== ($chain = ltrim($chain))) {
            if (!preg_match('/^->\\s*add\\s*\\(/', $chain, $add)) {
                return false;
            }
            $open = \strlen($add[0]) - 1;
            $close = $this->delimiters->matching($chain, $open, '(', ')');
            if (null === $close) {
                return false;
            }
            $chain = substr($chain, $close + 1);
        }

        return true;
    }

    /** @return array<string, true> */
    private function formBuilderVariables(PhpDocument $php): array
    {
        $variables = [];
        foreach ($php->typedVariables() as $variable) {
            if (\in_array('Symfony\\Component\\Form\\FormBuilderInterface', $variable->types(), true)) {
                $variables[$variable->name()] = true;
            }
        }

        return $variables;
    }

    private function typedMethodParameter(PhpDocument $php, string $className, string $methodName, string $type): ?PhpTypedVariable
    {
        $parameters = [];
        foreach ($php->typedVariables() as $variable) {
            if (PhpTypedVariableKind::Parameter === $variable->kind()
                && $className === $variable->className()
                && $methodName === $variable->methodName()
                && \in_array($type, $variable->types(), true)
            ) {
                $parameters[] = $variable;
            }
        }
        usort($parameters, static fn (PhpTypedVariable $left, PhpTypedVariable $right): int => $left->nameStartOffset() <=> $right->nameStartOffset());

        return $parameters[0] ?? null;
    }

    /** @param array{text: string, offset: int} $expression */
    private function staticClassName(string $source, array $expression, PhpDocument $php): ?string
    {
        $references = [];
        $end = $expression['offset'] + \strlen($expression['text']);
        foreach ($php->classReferences() as $reference) {
            if ($reference->startOffset() >= $expression['offset'] && $reference->endOffset() <= $end) {
                $references[] = $reference;
            }
        }
        if (1 !== \count($references)) {
            return null;
        }
        $reference = $references[0];
        $rawName = substr($source, $reference->startOffset(), $reference->endOffset() - $reference->startOffset());
        if (\in_array(strtolower(ltrim($rawName, '\\')), ['self', 'static', 'parent'], true)) {
            return null;
        }
        $before = trim(substr($source, $expression['offset'], $reference->startOffset() - $expression['offset']));
        $after = preg_replace('/\\s+/', '', substr($source, $reference->endOffset(), $end - $reference->endOffset()));

        return '' === $before && '::class' === $after ? $reference->className() : null;
    }

    private function classReferenceArgument(PhpDocument $php, ?PhpArgument $argument): ?PhpClassReference
    {
        $start = $argument?->expressionStartOffset();
        $end = $argument?->expressionEndOffset();
        if (!\is_int($start) || !\is_int($end)) {
            return null;
        }
        $references = [];
        foreach ($php->classReferences() as $reference) {
            if ($reference->startOffset() >= $start && $reference->endOffset() <= $end) {
                $references[] = $reference;
            }
        }

        return 1 === \count($references) ? $references[0] : null;
    }

    /** @return array<string, array{text: string, offset: int}>|null */
    private function arrayEntries(string $text, int $base = 0): ?array
    {
        if (!preg_match('/^\\s*\\[(.*)\\]\\s*$/s', $text, $array, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $entries = [];
        foreach ($this->arguments($array[1][0], $base + $array[1][1]) as $entry) {
            if ('' === trim($entry['text'])) {
                continue;
            }
            if (!preg_match('/^\\s*((?:"[^"]*")|(?:\'[^\']*\'))\\s*=>\\s*(.*?)\\s*$/s', $entry['text'], $match, \PREG_OFFSET_CAPTURE)
                || null === ($key = $this->quotedIdentifier($match[1][0]))
            ) {
                return null;
            }
            $entries[$key] = ['text' => $match[2][0], 'offset' => $entry['offset'] + $match[2][1]];
        }

        return $entries;
    }

    private function quotedIdentifier(string $text): ?string
    {
        if (!preg_match('/^\\s*(["\'])([A-Za-z_][A-Za-z0-9_]*)\\1\\s*$/', $text, $match)) {
            return null;
        }

        return $match[2];
    }

    /** @return array{name: string, range: Range}|null */
    private function quotedIdentifierArgument(string $document, PhpArgument $argument): ?array
    {
        $expression = $argument->expression();
        $offset = $argument->expressionStartOffset();
        if (!\is_string($expression) || !\is_int($offset) || !preg_match('/^\\s*(["\'])([A-Za-z_][A-Za-z0-9_]*)\\1\\s*$/', $expression, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $name = $match[2][0];

        return [
            'name' => $name,
            'range' => $this->converter->toRange($document, $offset + $match[2][1], \strlen($name)),
        ];
    }

    /** @param list<PhpArgument> $arguments */
    private function formPropertyName(array $arguments, string $field): ?string
    {
        foreach (\array_slice($arguments, 1) as $argument) {
            if (null !== $argument->name()) {
                return null;
            }
        }
        if (!isset($arguments[2])) {
            return $field;
        }
        $expression = $arguments[2]->expression();
        $offset = $arguments[2]->expressionStartOffset();
        if (!\is_string($expression) || !\is_int($offset) || null === $options = $this->arrayEntries($expression, $offset)) {
            return null;
        }
        if (isset($options['mapped'])) {
            $mapped = trim($options['mapped']['text']);
            if ('false' === $mapped) {
                return null;
            }
            if ('true' !== $mapped) {
                return null;
            }
        }
        if (!isset($options['property_path'])) {
            return $field;
        }
        $propertyPath = trim($options['property_path']['text']);
        if ('null' === $propertyPath) {
            return $field;
        }
        if ('false' === $propertyPath) {
            return null;
        }

        return $this->quotedIdentifier($propertyPath);
    }

    private function enclosingClass(PhpDocument $php, int $offset): ?string
    {
        foreach ($php->typeDeclarations() as $type) {
            if ($type->contains($offset)) {
                return $type->name();
            }
        }

        return null;
    }

    /** @return list<array{text: string, offset: int}> */
    private function arguments(string $text, int $base): array
    {
        $arguments = [];
        $start = 0;
        $stack = [];
        $quote = null;
        $escaped = false;
        $length = \strlen($text);
        for ($index = 0; $index < $length; ++$index) {
            $character = $text[$index];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ('"' === $character || "'" === $character) {
                $quote = $character;
            } elseif (str_contains('([{', $character)) {
                $stack[] = $character;
            } elseif (str_contains(')]}', $character)) {
                array_pop($stack);
            } elseif (',' === $character && [] === $stack) {
                $arguments[] = ['text' => substr($text, $start, $index - $start), 'offset' => $base + $start];
                $start = $index + 1;
            }
        }
        $arguments[] = ['text' => substr($text, $start), 'offset' => $base + $start];

        return $arguments;
    }

    /** @return list<array{name: string, range: Range}> */
    private function arrayKeys(string $document, PhpArgument $argument): array
    {
        $text = $argument->expression();
        $offset = $argument->expressionStartOffset();
        if (!\is_string($text) || !\is_int($offset)) {
            return [];
        }
        if (!preg_match('/^\s*\[/', $text, $open, \PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $keys = [];
        $depth = 0;
        $length = \strlen($text);
        for ($index = 0; $index < $length; ++$index) {
            $character = $text[$index];
            if ('[' === $character) {
                ++$depth;
                continue;
            }
            if (']' === $character) {
                --$depth;
                continue;
            }
            if (1 !== $depth || ('"' !== $character && "'" !== $character)) {
                continue;
            }
            $end = $index + 1;
            while ($end < $length && $text[$end] !== $character) {
                $end += '\\' === $text[$end] ? 2 : 1;
            }
            if ($end >= $length || !preg_match('/^\s*=>/', substr($text, $end + 1))) {
                $index = $end;
                continue;
            }
            $name = substr($text, $index + 1, $end - $index - 1);
            $absolute = $offset + $index + 1;
            $keys[] = ['name' => $name, 'range' => $this->converter->toRange($document, $absolute, \strlen($name))];
            $index = $end;
        }

        return $keys;
    }

    /** @return list<MetadataSourceSymbol> */
    private function quotedSymbols(MetadataSymbolKind $kind, string $uri, string $text, string $fragment, int $base, bool $declaration): array
    {
        preg_match_all('/["\']([A-Za-z_][A-Za-z0-9_.:-]*)["\']/', $fragment, $matches, \PREG_OFFSET_CAPTURE);
        $symbols = [];
        foreach ($matches[1] as [$name, $offset]) {
            $symbols[] = new MetadataSourceSymbol($kind, $name, $uri, $this->converter->toRange($text, $base + $offset, \strlen($name)), $declaration);
        }

        return $symbols;
    }

    /** @param list<array{label: string, class: string}> $candidates */
    private function context(MetadataCompletionKind $kind, string $prefix, string $text, int $offset, ?string $owner = null, array $candidates = []): MetadataCompletionContext
    {
        return new MetadataCompletionContext($kind, $prefix, $this->converter->toRange($text, $offset, \strlen($prefix)), $owner, $candidates);
    }

    /**
     * @param list<MetadataSourceSymbol> $symbols
     *
     * @return list<MetadataSourceSymbol>
     */
    private function unique(array $symbols): array
    {
        $unique = [];
        foreach ($symbols as $symbol) {
            $key = $symbol->kind()->value.'|'.$symbol->range()->start()->line().'|'.$symbol->range()->start()->character();
            $unique[$key] = $symbol;
        }

        return array_values($unique);
    }
}
