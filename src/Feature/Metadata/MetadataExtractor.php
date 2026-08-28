<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodDeclaration;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

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
        $source = $this->phpComments->mask($text);
        $options = [];
        foreach ($this->calls($source, $php) as $call) {
            $typeIndex = 'createNamed' === $call['name'] ? 1 : ('add' === $call['name'] ? 1 : 0);
            $optionsIndex = 'createNamed' === $call['name'] ? 3 : 2;
            if (!isset($call['arguments'][$typeIndex], $call['arguments'][$optionsIndex])) {
                continue;
            }
            if (!preg_match('/^\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::class\b/', $call['arguments'][$typeIndex]['text'], $type)) {
                continue;
            }
            $class = $php->resolveName($type[1]);
            foreach ($this->arrayKeys($text, $call['arguments'][$optionsIndex]) as $key) {
                $options[] = ['class' => $class, 'option' => $key['name'], 'range' => $key['range']];
            }
        }

        return $options;
    }

    /** @return list<array{constraint: string, option: string, range: Range}> */
    public function constraintOptions(string $text): array
    {
        $php = $this->phpParser->parse($text);
        $source = $this->phpComments->mask($text);
        $options = [];
        preg_match_all('/#\[\s*([\\\\A-Za-z_][A-Za-z0-9_\\\\]*)\s*\((.*?)\)\s*\]/s', $source, $attributes, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($attributes as $attribute) {
            if (str_contains($attribute[2][0], 'new ')) {
                continue;
            }
            foreach ($this->arguments($attribute[2][0], $attribute[2][1]) as $argument) {
                if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*:(?!:)/', $argument['text'], $named, \PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $name = $named[1][0];
                $absolute = $argument['offset'] + $named[1][1];
                $options[] = ['constraint' => $php->resolveName($attribute[1][0]), 'option' => $name, 'range' => $this->converter->toRange($text, $absolute, \strlen($name))];
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
                || null === ($body = $this->methodBody($source, $method))
                || null === ($resolver = $this->firstParameterVariable($method->signature()))
            ) {
                continue;
            }
            $dataClass = null;
            preg_match_all('/\\$'.preg_quote($resolver, '/').'\\s*->\\s*(setDefaults|setDefault)\\s*\\(/', $body['text'], $calls, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($calls as $call) {
                $open = $call[0][1] + \strlen($call[0][0]) - 1;
                $close = $this->delimiters->matching($body['text'], $open, '(', ')');
                if (null === $close) {
                    continue;
                }
                $arguments = $this->arguments(substr($body['text'], $open + 1, $close - $open - 1), $body['start'] + $open + 1);
                if ('setDefaults' === $call[1][0]) {
                    if (!isset($arguments[0]) || null === $entries = $this->arrayEntries($arguments[0]['text'])) {
                        $dataClass = null;
                        continue;
                    }
                    if (!\array_key_exists('data_class', $entries)) {
                        continue;
                    }
                    $expression = $entries['data_class'];
                } else {
                    if ('data_class' !== $this->quotedIdentifier($arguments[0]['text'] ?? '')) {
                        continue;
                    }
                    $expression = $arguments[1]['text'] ?? '';
                }
                $dataClass = $this->staticClassName($expression, $php);
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
        preg_match_all('/\b(?:final\s+|abstract\s+|readonly\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)[^\{]*\{/', $source, $classes, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($classes as $class) {
            $className = $php->resolveName($class[1][0]);
            $symbols[] = new MetadataSourceSymbol(
                MetadataSymbolKind::MappedClass,
                $className,
                $uri,
                $this->converter->toRange($text, $class[1][1], \strlen($class[1][0])),
                true,
            );
            if (preg_match('/\bextends\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $class[0][0], $parent)
                && 'Symfony\\Component\\Validator\\Constraint' === $php->resolveName($parent[1])) {
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::Constraint,
                    $class[1][0],
                    $uri,
                    $this->converter->toRange($text, $class[1][1], \strlen($class[1][0])),
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
                || null === ($body = $this->methodBody($source, $method))
                || null === ($builder = $this->firstParameterVariable($method->signature()))
            ) {
                continue;
            }
            preg_match_all('/->\\s*add\\s*\\(/', $body['text'], $calls, \PREG_OFFSET_CAPTURE);
            foreach ($calls[0] as [$matched, $relativeOffset]) {
                $callOffset = $body['start'] + $relativeOffset;
                if (!$this->isDirectFormBuilderCall($source, $callOffset, $builder)) {
                    continue;
                }
                $open = $callOffset + \strlen($matched) - 1;
                $close = $this->delimiters->matching($source, $open, '(', ')');
                if (null === $close) {
                    continue;
                }
                $arguments = $this->arguments(substr($source, $open + 1, $close - $open - 1), $open + 1);
                $field = isset($arguments[0]) ? $this->quotedIdentifierArgument($text, $arguments[0]) : null;
                $property = null === $field ? null : $this->formPropertyName($arguments, $field['name']);
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

        $groupsImported = 'Symfony\\Component\\Serializer\\Attribute\\Groups' === ($php->imports()['Groups'] ?? null)
            || 'Symfony\\Component\\Serializer\\Annotation\\Groups' === ($php->imports()['Groups'] ?? null);
        preg_match_all('/#\[\s*((?:[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\\\\)?Groups)\s*\((.*?)\)\s*\]/s', $source, $groups, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($groups as $group) {
            if ('Groups' === $group[1][0] && !$groupsImported) {
                continue;
            }
            array_push($symbols, ...$this->quotedSymbols(MetadataSymbolKind::SerializerGroup, $uri, $text, $group[2][0], $group[2][1], true));
        }
        preg_match_all('/["\']groups["\']\s*=>\s*\[(.*?)\]/s', $source, $groupReferences, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($groupReferences as $group) {
            array_push($symbols, ...$this->quotedSymbols(MetadataSymbolKind::SerializerGroup, $uri, $text, $group[1][0], $group[1][1], false));
        }
        $constraintNamespace = 'Symfony\\Component\\Validator\\Constraints\\';
        preg_match_all('/#\[\s*([\\\\A-Za-z_][A-Za-z0-9_\\\\]*)/', $source, $constraintReferences, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($constraintReferences as $reference) {
            $className = $php->resolveName($reference[1][0]);
            if (!str_starts_with($className, $constraintNamespace)) {
                continue;
            }
            $name = substr($className, \strlen($constraintNamespace));
            if (str_contains($name, '\\')) {
                continue;
            }
            $separator = strrpos($reference[1][0], '\\');
            $segmentOffset = false === $separator ? 0 : $separator + 1;
            $offset = $reference[1][1] + $segmentOffset;
            $length = \strlen($reference[1][0]) - $segmentOffset;
            $symbols[] = new MetadataSourceSymbol(MetadataSymbolKind::Constraint, $name, $uri, $this->converter->toRange($text, $offset, $length), false);
        }
        foreach ($php->imports() as $alias => $className) {
            if ($className === rtrim($constraintNamespace, '\\') || str_starts_with($className, $constraintNamespace)) {
                continue;
            }
            if (!str_contains($className, '\\Validator\\') && !str_contains($className, '\\Constraints\\')) {
                continue;
            }
            preg_match_all('/#\[\s*'.preg_quote($alias, '/').'\b/', $source, $references, \PREG_OFFSET_CAPTURE);
            foreach ($references[0] as [$reference, $offset]) {
                $nameOffset = $offset + strrpos($reference, $alias);
                $symbols[] = new MetadataSourceSymbol(MetadataSymbolKind::Constraint, $alias, $uri, $this->converter->toRange($text, $nameOffset, \strlen($alias)), false);
            }
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

    /** @return list<array{name: string, arguments: list<array{text: string, offset: int}>}> */
    private function calls(string $text, PhpDocument $php): array
    {
        $formBuilders = $this->formBuilderVariables($php);
        preg_match_all('/(?:(->)\s*)?\b(createForm|createNamed|add)\s*\(/', $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        $calls = [];
        foreach ($matches as $match) {
            if ('add' === $match[2][0] && ('' === $match[1][0] || !$this->isFormBuilderCall($text, $match[0][1], $formBuilders))) {
                continue;
            }
            $open = $match[0][1] + \strlen($match[0][0]) - 1;
            $close = $this->delimiters->matching($text, $open, '(', ')');
            if (null === $close) {
                continue;
            }
            $calls[] = ['name' => $match[2][0], 'arguments' => $this->arguments(substr($text, $open + 1, $close - $open - 1), $open + 1)];
        }

        return $calls;
    }

    /**
     * @param array<string, true> $variables
     */
    private function isFormBuilderCall(string $text, int $offset, array $variables): bool
    {
        $before = substr($text, 0, $offset);
        $statementStart = max((int) strrpos($before, ';'), (int) strrpos($before, '{'));
        $statement = substr($before, $statementStart);
        foreach (array_keys($variables) as $variable) {
            if (preg_match('/\\$'.preg_quote($variable, '/').'\b/', $statement)) {
                return true;
            }
        }

        return false;
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
        $statement = ltrim(substr($before, $statementStart), " \t\r\n;{");
        $builder = '$'.$variable;
        if (!str_starts_with($statement, $builder)) {
            return false;
        }
        $chain = substr($statement, \strlen($builder));
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

    /** @return array{text: string, start: int, end: int}|null */
    private function methodBody(string $source, PhpMethodDeclaration $method): ?array
    {
        $parametersOpen = strpos($source, '(', $method->nameEndOffset());
        if (false === $parametersOpen || null === $parametersClose = $this->delimiters->matching($source, $parametersOpen, '(', ')')) {
            return null;
        }
        $bodyOpen = strpos($source, '{', $parametersClose + 1);
        $semicolon = strpos($source, ';', $parametersClose + 1);
        if (false === $bodyOpen || (false !== $semicolon && $semicolon < $bodyOpen)) {
            return null;
        }
        $bodyClose = $this->delimiters->matching($source, $bodyOpen, '{', '}');
        if (null === $bodyClose) {
            return null;
        }

        return ['text' => substr($source, $bodyOpen + 1, $bodyClose - $bodyOpen - 1), 'start' => $bodyOpen + 1, 'end' => $bodyClose];
    }

    private function firstParameterVariable(string $signature): ?string
    {
        $open = strpos($signature, '(');
        if (false === $open || !preg_match('/\\$([A-Za-z_][A-Za-z0-9_]*)/', substr($signature, $open + 1), $variable)) {
            return null;
        }

        return $variable[1];
    }

    private function staticClassName(string $expression, PhpDocument $php): ?string
    {
        if (!preg_match('/^\\s*([\\\\A-Za-z_][A-Za-z0-9_\\\\]*)\\s*::\\s*class\\s*$/', $expression, $class)
            || \in_array(strtolower(ltrim($class[1], '\\')), ['self', 'static', 'parent'], true)
        ) {
            return null;
        }

        return $php->resolveName($class[1]);
    }

    /** @return array<string, string>|null */
    private function arrayEntries(string $text): ?array
    {
        if (!preg_match('/^\\s*\\[(.*)\\]\\s*$/s', $text, $array)) {
            return null;
        }
        $entries = [];
        foreach ($this->arguments($array[1], 0) as $entry) {
            if ('' === trim($entry['text'])) {
                continue;
            }
            if (!preg_match('/^\\s*((?:"[^"]*")|(?:\'[^\']*\'))\\s*=>\\s*(.*?)\\s*$/s', $entry['text'], $match)
                || null === ($key = $this->quotedIdentifier($match[1]))
            ) {
                return null;
            }
            $entries[$key] = $match[2];
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

    /**
     * @param array{text: string, offset: int} $argument
     *
     * @return array{name: string, range: Range}|null
     */
    private function quotedIdentifierArgument(string $document, array $argument): ?array
    {
        if (!preg_match('/^\\s*(["\'])([A-Za-z_][A-Za-z0-9_]*)\\1\\s*$/', $argument['text'], $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $name = $match[2][0];

        return [
            'name' => $name,
            'range' => $this->converter->toRange($document, $argument['offset'] + $match[2][1], \strlen($name)),
        ];
    }

    /** @param list<array{text: string, offset: int}> $arguments */
    private function formPropertyName(array $arguments, string $field): ?string
    {
        foreach (\array_slice($arguments, 1) as $argument) {
            if (preg_match('/^\\s*[A-Za-z_][A-Za-z0-9_]*\\s*:(?!:)/', $argument['text'])) {
                return null;
            }
        }
        if (!isset($arguments[2])) {
            return $field;
        }
        $options = $this->arrayEntries($arguments[2]['text']);
        if (null === $options) {
            return null;
        }
        if (isset($options['mapped'])) {
            $mapped = trim($options['mapped']);
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
        $propertyPath = trim($options['property_path']);
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

    /**
     * @param array{text: string, offset: int} $argument
     *
     * @return list<array{name: string, range: Range}>
     */
    private function arrayKeys(string $document, array $argument): array
    {
        $text = $argument['text'];
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
            $absolute = $argument['offset'] + $index + 1;
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
