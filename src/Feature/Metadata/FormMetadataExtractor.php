<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\ClassNameKey;
use Symfony\Lsp\Parser\DelimiterScanner;
use Symfony\Lsp\Parser\DelimiterSegment;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpLiteralArrayKeyParser;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodDeclaration;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;
use Symfony\Lsp\Parser\Php\PhpReceiverMatch;
use Symfony\Lsp\Parser\Php\PhpTypedVariable;
use Symfony\Lsp\Parser\Php\PhpTypedVariableKind;

final class FormMetadataExtractor
{
    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/D';
    private const FORM_FACTORY_TYPES = [
        'Symfony\\Component\\Form\\FormFactoryInterface',
        'Symfony\\Component\\Form\\FormFactory',
    ];

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpLiteralArrayKeyParser $arrayKeys,
    ) {
    }

    /** @return list<FormDataClass> */
    public function dataClasses(string $source, PhpDocument $php): array
    {
        $classes = [];
        foreach ($php->methodDeclarations as $method) {
            if ('configureOptions' !== $method->name
                || null === ($resolver = $this->typedMethodParameter($php, $method, 'Symfony\\Component\\OptionsResolver\\OptionsResolver'))
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
                $classes[ClassNameKey::from($method->className)] = new FormDataClass($method->className, $dataClass);
            }
        }

        return array_values($classes);
    }

    /**
     * @param list<FormDataClass> $formDataClasses
     *
     * @return list<MetadataSourceSymbol>
     */
    public function symbols(string $uri, string $text, string $source, PhpDocument $php, array $formDataClasses): array
    {
        $symbols = [];
        $dataClasses = [];
        foreach ($formDataClasses as $formDataClass) {
            $dataClasses[ClassNameKey::from($formDataClass->formClass)] = $formDataClass->dataClass;
        }
        foreach ($php->methodDeclarations as $method) {
            $dataClass = $dataClasses[ClassNameKey::from($method->className)] ?? null;
            if (null === $dataClass
                || 'buildForm' !== $method->name
                || null === ($builder = $this->typedMethodParameter($php, $method, 'Symfony\\Component\\Form\\FormBuilderInterface'))
            ) {
                continue;
            }
            foreach ($php->methodCalls as $call) {
                if ('add' !== $call->method
                    || $method->className !== $call->className
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

    /** @return list<FormOptionReference> */
    public function options(string $text, string $source, PhpDocument $php): array
    {
        $options = [];
        foreach ($this->formCalls($source, $php) as $call) {
            $type = $call->positionalArgument($this->formTypeIndex($call))?->completeClassReference;
            $argument = $this->formOptionsArgument($call);
            if (null === $type || null === $argument) {
                continue;
            }
            foreach ($this->arrayKeys->parseArgument($argument, allowNestedUnpacking: true, collectPartialLiteralKeys: true) ?? [] as $key) {
                $options[] = new FormOptionReference(
                    $type->className,
                    $key->value,
                    $this->converter->toRange($text, $key->startOffset, $key->endOffset - $key->startOffset),
                );
            }
        }

        return $options;
    }

    public function completionContext(string $text, string $source, PhpDocument $php, int $offset): ?MetadataCompletionContext
    {
        $cursor = $php->argumentCursorAt($offset);
        $call = $cursor?->call;
        if (null === $cursor || null === $cursor->quote || !$call instanceof PhpMethodCall || !$this->isFormCall($source, $php, $call)) {
            return null;
        }
        if ('add' === $call->method && $cursor->isArgumentLiteral() && $cursor->argument === $call->positionalArgument(0)) {
            $builder = $this->formBuilderVariableForCall($source, $php, $call);
            $named = '' === $cursor->prefix || 1 === preg_match(self::IDENTIFIER_PATTERN, $cursor->prefix);

            return !$named || null === $call->className || null === $builder || !$this->isDirectFormBuilderReceiver($call->receiver, $builder->name)
                ? null
                : $this->context(MetadataCompletionKind::FormProperty, $cursor->prefix, $text, $cursor->prefixStartOffset, $call->className);
        }
        $type = $call->positionalArgument($this->formTypeIndex($call))?->completeClassReference?->className;
        if (!$cursor->isArrayItemLiteral()
            || $cursor->argument !== $this->formOptionsArgument($call)
            || 1 !== preg_match(self::IDENTIFIER_PATTERN, $cursor->prefix)
            || null === $type
        ) {
            return null;
        }

        return $this->context(MetadataCompletionKind::FormOption, $cursor->prefix, $text, $cursor->prefixStartOffset, $type);
    }

    private function formTypeIndex(PhpMethodCall $call): int
    {
        return 'createForm' === $call->method ? 0 : 1;
    }

    private function formOptionsArgument(PhpMethodCall $call): ?PhpArgument
    {
        return $call->positionalArgument('createNamed' === $call->method ? 3 : 2);
    }

    /** Whether the call is one Symfony reads form options from: a form creator or a direct form builder field. */
    private function isFormCall(string $source, PhpDocument $php, PhpMethodCall $call): bool
    {
        if (!\in_array($call->method, ['createForm', 'createNamed', 'add'], true)) {
            return false;
        }

        return 'add' === $call->method
            ? null !== $this->formBuilderVariableForCall($source, $php, $call)
            : $this->createsFormThroughSymfony($php, $call);
    }

    /** @return list<PhpMethodCall> */
    private function formCalls(string $source, PhpDocument $php): array
    {
        return array_values(array_filter($php->methodCalls, fn (PhpMethodCall $call): bool => $this->isFormCall($source, $php, $call)));
    }

    /**
     * Whether a `createForm` or `createNamed` call is Symfony's: a controller
     * call on `$this`, or a call on a form factory.
     */
    private function createsFormThroughSymfony(PhpDocument $php, PhpMethodCall $call): bool
    {
        return match ($call->receiverContext->kind) {
            PhpMethodReceiverKind::This => true,
            PhpMethodReceiverKind::ThisProperty, PhpMethodReceiverKind::Variable => PhpReceiverMatch::Matches === $php->matchReceiver($call, ...self::FORM_FACTORY_TYPES),
            PhpMethodReceiverKind::Other => false,
        };
    }

    private function formBuilderVariableForCall(string $source, PhpDocument $php, PhpMethodCall $call): ?PhpTypedVariable
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
        do {
            if ([] !== $variables = $php->receiverVariables($call)) {
                return $variables;
            }
        } while (null !== $call = $php->receiverCall($call));

        return [];
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
            $close = DelimiterScanner::close($chain, \strlen($add[0]) - 1);
            if (null === $close) {
                return false;
            }
            $chain = substr($chain, $close + 1);
        }

        return true;
    }

    private function typedMethodParameter(PhpDocument $php, PhpMethodDeclaration $method, string $type): ?PhpTypedVariable
    {
        $parameter = $method->parameters[0] ?? null;
        if (null === $parameter || !\in_array($type, $parameter->types, true)) {
            return null;
        }
        foreach ($php->typedVariables as $variable) {
            if ($parameter->nameStartOffset === $variable->nameStartOffset) {
                return $variable;
            }
        }

        return null;
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
        if (\in_array(ClassNameKey::from($rawName), ['self', 'static', 'parent'], true)) {
            return null;
        }
        $before = trim(substr($source, $expression['offset'], $reference->startOffset - $expression['offset']));
        $after = preg_replace('/\\s+/', '', substr($source, $reference->endOffset, $end - $reference->endOffset));

        return '' === $before && '::class' === $after ? $reference->className : null;
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
            DelimiterScanner::split($items, ',', $itemsOffset, phpComments: true),
            fn (DelimiterSegment $entry): bool => $this->hasCode($entry->text),
        ));
        if (\count($arguments) !== \count($keys)) {
            return null;
        }
        $entries = [];
        foreach ($arguments as $index => $entry) {
            $key = $keys[$index];
            $entryEnd = $entry->offset + \strlen($entry->text);
            if ($key->startOffset < $entry->offset || $key->endOffset >= $entryEnd || 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key->value)) {
                return null;
            }
            $tailOffset = $key->endOffset - $entry->offset + 1;
            if (!preg_match('/^\\s*=>\\s*(.*?)\\s*$/s', substr($entry->text, $tailOffset), $match, \PREG_OFFSET_CAPTURE)) {
                return null;
            }
            $entries[$key->value] = ['text' => $match[1][0], 'offset' => $entry->offset + $tailOffset + $match[1][1]];
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

    private function hasCode(string $text): bool
    {
        foreach (\PhpToken::tokenize('<?php '.$text) as $token) {
            if (!$token->is([\T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                return true;
            }
        }

        return false;
    }

    private function context(MetadataCompletionKind $kind, string $prefix, string $text, int $offset, ?string $owner = null): MetadataCompletionContext
    {
        return new MetadataCompletionContext($kind, $prefix, $this->converter->toRange($text, $offset, \strlen($prefix)), $owner);
    }
}
