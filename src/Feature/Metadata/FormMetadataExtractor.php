<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpClassReference;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpLiteralArrayKeyParser;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpTypedVariable;
use Symfony\Lsp\Parser\Php\PhpTypedVariableKind;

final class FormMetadataExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly BalancedDelimiterMatcher $delimiters,
        private readonly PhpLiteralArrayKeyParser $arrayKeys,
    ) {
    }

    /** @return list<FormDataClass> */
    public function dataClasses(string $source, PhpDocument $php): array
    {
        $classes = [];
        foreach ($php->methodDeclarations as $method) {
            if ('configureOptions' !== $method->name
                || 'Symfony\\Component\\OptionsResolver\\OptionsResolver' !== $method->firstParameterType
                || null === ($resolver = $this->typedMethodParameter($php, $method->className, $method->name, 'Symfony\\Component\\OptionsResolver\\OptionsResolver'))
            ) {
                continue;
            }
            $dataClass = null;
            foreach ($php->methodCalls as $call) {
                if (!\in_array($call->method, ['setDefaults', 'setDefault'], true)
                    || $method->className !== $call->className
                    || $method->name !== $call->enclosingMethod
                    || !\in_array($resolver, $php->receiverVariables($call), true)
                ) {
                    continue;
                }
                if ('setDefaults' === $call->method) {
                    $argument = $call->positionalArgument(0);
                    $expression = $argument?->expression;
                    $offset = $argument?->expressionStartOffset;
                    if (!\is_string($expression) || !\is_int($offset) || null === $entries = $this->arrayEntries($expression, $offset)) {
                        $dataClass = null;
                        continue;
                    }
                    if (!\array_key_exists('data_class', $entries)) {
                        continue;
                    }
                    $dataClassExpression = $entries['data_class'];
                } else {
                    if ('data_class' !== $this->quotedIdentifier($call->positionalArgument(0)->expression ?? '')) {
                        continue;
                    }
                    $argument = $call->positionalArgument(1);
                    $expression = $argument?->expression;
                    $offset = $argument?->expressionStartOffset;
                    if (!\is_string($expression) || !\is_int($offset)) {
                        $dataClass = null;
                        continue;
                    }
                    $dataClassExpression = ['text' => $expression, 'offset' => $offset];
                }
                $dataClass = $this->staticClassName($source, $dataClassExpression, $php);
            }
            if (null !== $dataClass) {
                $classes[strtolower(ltrim($method->className, '\\'))] = new FormDataClass($method->className, $dataClass);
            }
        }

        return array_values($classes);
    }

    /**
     * @param list<FormDataClass> $formDataClasses
     *
     * @return list<MetadataSourceSymbol>
     */
    public function symbols(string $uri, string $text, PhpDocument $php, array $formDataClasses): array
    {
        $symbols = [];
        $dataClasses = [];
        foreach ($formDataClasses as $formDataClass) {
            $dataClasses[strtolower(ltrim($formDataClass->formClass, '\\'))] = $formDataClass->dataClass;
        }
        foreach ($php->methodDeclarations as $method) {
            $dataClass = $dataClasses[strtolower(ltrim($method->className, '\\'))] ?? null;
            if (null === $dataClass
                || 'buildForm' !== $method->name
                || 'Symfony\\Component\\Form\\FormBuilderInterface' !== $method->firstParameterType
                || null === ($builder = $this->typedMethodParameter($php, $method->className, $method->name, 'Symfony\\Component\\Form\\FormBuilderInterface'))
            ) {
                continue;
            }
            foreach ($php->methodCalls as $call) {
                if ('add' !== $call->method
                    || $method->className !== $call->className
                    || $method->name !== $call->enclosingMethod
                    || !\in_array($builder, $this->formBuilderReceiverVariables($php, $call), true)
                    || !$this->isDirectFormBuilderReceiver($call->receiver, $builder->name)
                ) {
                    continue;
                }
                $field = null === $call->positionalArgument(0) ? null : $this->quotedIdentifierArgument($text, $call->positionalArgument(0));
                $property = null === $field ? null : $this->formPropertyName($call->arguments, $field['name']);
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

        return $symbols;
    }

    /** @return list<array{class: string, option: string, range: Range}> */
    public function options(string $text, string $source, PhpDocument $php): array
    {
        $options = [];
        foreach ($this->formCalls($php) as $call) {
            $typeIndex = 'createNamed' === $call->method ? 1 : ('add' === $call->method ? 1 : 0);
            $optionsIndex = 'createNamed' === $call->method ? 3 : 2;
            $type = $this->leadingClassReferenceArgument($source, $php, $call->positionalArgument($typeIndex));
            $argument = $call->positionalArgument($optionsIndex);
            if (null === $type || null === $argument) {
                continue;
            }
            foreach ($this->arrayKeys->parseArgument($argument, allowNestedUnpacking: true, collectPartialLiteralKeys: true) ?? [] as $key) {
                $options[] = [
                    'class' => $type->className,
                    'option' => $key->value,
                    'range' => $this->converter->toRange($text, $key->startOffset, $key->endOffset - $key->startOffset),
                ];
            }
        }

        return $options;
    }

    public function completionContext(string $text, string $source, PhpDocument $php, int $offset): ?MetadataCompletionContext
    {
        preg_match_all('/(?:(->)\s*)?\b(createForm|createNamed|add)\s*\(/', substr($source, 0, $offset), $calls, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach (array_reverse($calls) as $call) {
            if ('add' === $call[2][0] && null === $this->directFormBuilderVariable($source, $call[0][1], $this->formBuilderVariables($php, $call[0][1]))) {
                continue;
            }
            $open = $call[0][1] + \strlen($call[0][0]) - 1;
            $close = $this->delimiters->matching($source, $open, '(', ')');
            if (null !== $close && $close < $offset) {
                continue;
            }
            $arguments = $this->arguments(substr($source, $open + 1, $offset - $open - 1), $open + 1);
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

    /** @return list<PhpMethodCall> */
    private function formCalls(PhpDocument $php): array
    {
        $calls = [];
        foreach ($php->methodCalls as $call) {
            if (!\in_array($call->method, ['createForm', 'createNamed', 'add'], true)) {
                continue;
            }
            if ('add' === $call->method && null === $this->formBuilderVariableForCall($php, $call)) {
                continue;
            }
            $calls[] = $call;
        }

        return $calls;
    }

    private function formBuilderVariableForCall(PhpDocument $php, PhpMethodCall $call): ?PhpTypedVariable
    {
        foreach ($this->formBuilderReceiverVariables($php, $call) as $variable) {
            if (PhpTypedVariableKind::Parameter !== $variable->kind
                || !\in_array('Symfony\\Component\\Form\\FormBuilderInterface', $variable->types, true)
                || 1 !== preg_match('/^\s*\\$'.preg_quote($variable->name, '/').'\b/', $call->receiver)
            ) {
                continue;
            }

            return $variable;
        }

        return null;
    }

    /** @return list<PhpTypedVariable> */
    private function formBuilderReceiverVariables(PhpDocument $php, PhpMethodCall $call): array
    {
        if ([] !== $variables = $php->receiverVariables($call)) {
            return $variables;
        }
        foreach ($php->methodCalls as $receiverCall) {
            if ($call === $receiverCall
                || $call->startOffset !== $receiverCall->startOffset
                || $receiverCall->endOffset > $call->receiverContext->endOffset
                || [] === $variables = $php->receiverVariables($receiverCall)
            ) {
                continue;
            }

            return $variables;
        }

        return [];
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
    private function formBuilderVariables(PhpDocument $php, int $offset): array
    {
        $className = $this->enclosingClass($php, $offset);
        if (null === $className) {
            return [];
        }
        $methodName = null;
        $methodOffset = -1;
        foreach ($php->methodDeclarations as $method) {
            if ($className !== $method->className || $method->nameStartOffset > $offset || $method->nameStartOffset <= $methodOffset) {
                continue;
            }
            $methodName = $method->name;
            $methodOffset = $method->nameStartOffset;
        }
        if (null === $methodName) {
            return [];
        }
        $variables = [];
        foreach ($php->typedVariables as $variable) {
            if (PhpTypedVariableKind::Parameter === $variable->kind
                && $className === $variable->className
                && $methodName === $variable->methodName
                && \in_array('Symfony\\Component\\Form\\FormBuilderInterface', $variable->types, true)
            ) {
                $variables[$variable->name] = true;
            }
        }

        return $variables;
    }

    private function typedMethodParameter(PhpDocument $php, string $className, string $methodName, string $type): ?PhpTypedVariable
    {
        $parameters = [];
        foreach ($php->typedVariables as $variable) {
            if (PhpTypedVariableKind::Parameter === $variable->kind
                && $className === $variable->className
                && $methodName === $variable->methodName
                && \in_array($type, $variable->types, true)
            ) {
                $parameters[] = $variable;
            }
        }
        usort($parameters, static fn (PhpTypedVariable $left, PhpTypedVariable $right): int => $left->nameStartOffset <=> $right->nameStartOffset);

        return $parameters[0] ?? null;
    }

    /** @param array{text: string, offset: int} $expression */
    private function staticClassName(string $source, array $expression, PhpDocument $php): ?string
    {
        $references = [];
        $end = $expression['offset'] + \strlen($expression['text']);
        foreach ($php->classReferences as $reference) {
            if ($reference->startOffset >= $expression['offset'] && $reference->endOffset <= $end) {
                $references[] = $reference;
            }
        }
        if (1 !== \count($references)) {
            return null;
        }
        $reference = $references[0];
        $rawName = substr($source, $reference->startOffset, $reference->endOffset - $reference->startOffset);
        if (\in_array(strtolower(ltrim($rawName, '\\')), ['self', 'static', 'parent'], true)) {
            return null;
        }
        $before = trim(substr($source, $expression['offset'], $reference->startOffset - $expression['offset']));
        $after = preg_replace('/\\s+/', '', substr($source, $reference->endOffset, $end - $reference->endOffset));

        return '' === $before && '::class' === $after ? $reference->className : null;
    }

    private function leadingClassReferenceArgument(string $source, PhpDocument $php, ?PhpArgument $argument): ?PhpClassReference
    {
        $reference = $php->soleClassReference($argument);
        $start = $argument?->expressionStartOffset;
        $end = $argument?->expressionEndOffset;
        if (null === $reference || !\is_int($start) || !\is_int($end)) {
            return null;
        }
        $before = trim(substr($source, $start, $reference->startOffset - $start));
        $after = substr($source, $reference->endOffset, $end - $reference->endOffset);

        return '' === $before && 1 === preg_match('/^\s*::\s*class\b/', $after) ? $reference : null;
    }

    /** @return array<string, array{text: string, offset: int}>|null */
    private function arrayEntries(string $text, int $base = 0): ?array
    {
        if (!preg_match('/^\\s*\\[(.*)\\]\\s*$/s', $text, $array, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $items = $array[1][0];
        $itemsOffset = $base + $array[1][1];
        $keys = $this->arrayKeys->parse($items, allowNestedUnpacking: true, sourceOffset: $itemsOffset);
        if (null === $keys) {
            return null;
        }
        $arguments = array_values(array_filter(
            $this->arguments($items, $itemsOffset),
            static fn (array $entry): bool => '' !== trim($entry['text']),
        ));
        if (\count($arguments) !== \count($keys)) {
            return null;
        }
        $entries = [];
        foreach ($arguments as $index => $entry) {
            $key = $keys[$index];
            $entryEnd = $entry['offset'] + \strlen($entry['text']);
            if ($key->startOffset < $entry['offset'] || $key->endOffset >= $entryEnd || 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key->value)) {
                return null;
            }
            $tailOffset = $key->endOffset - $entry['offset'] + 1;
            if (!preg_match('/^\\s*=>\\s*(.*?)\\s*$/s', substr($entry['text'], $tailOffset), $match, \PREG_OFFSET_CAPTURE)) {
                return null;
            }
            $entries[$key->value] = ['text' => $match[1][0], 'offset' => $entry['offset'] + $tailOffset + $match[1][1]];
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
        $expression = $argument->expression;
        $offset = $argument->expressionStartOffset;
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
            if (null !== $argument->name) {
                return null;
            }
        }
        if (!isset($arguments[2])) {
            return $field;
        }
        $expression = $arguments[2]->expression;
        $offset = $arguments[2]->expressionStartOffset;
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
        foreach ($php->typeDeclarations as $type) {
            if ($type->contains($offset)) {
                return $type->name;
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

    private function context(MetadataCompletionKind $kind, string $prefix, string $text, int $offset, ?string $owner = null): MetadataCompletionContext
    {
        return new MetadataCompletionContext($kind, $prefix, $this->converter->toRange($text, $offset, \strlen($prefix)), $owner);
    }
}
