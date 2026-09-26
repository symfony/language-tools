<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Index\ClassNameKey;
use Symfony\Lsp\Parser\DelimiterScanner;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpClassReference;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpLiteralArrayEntry;
use Symfony\Lsp\Parser\Php\PhpLiteralKind;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodDeclaration;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;
use Symfony\Lsp\Parser\Php\PhpReceiverMatch;
use Symfony\Lsp\Parser\Php\PhpStringLiteral;
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
                    $entries = $this->arrayEntries($php, $call->positionalArgument(0));
                    if (null === $entries) {
                        $dataClass = null;
                        continue;
                    }
                    if (!\array_key_exists('data_class', $entries)) {
                        continue;
                    }
                    $reference = $entries['data_class']->classReference;
                } else {
                    if ('data_class' !== $call->positionalArgument(0)?->stringLiteral?->value) {
                        continue;
                    }
                    $reference = $call->positionalArgument(1)?->completeClassReference;
                }
                $dataClass = $this->earlyBoundClassName($source, $reference);
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
                $field = $this->identifierArgument($call->positionalArgument(0));
                $property = null === $field ? null : $this->formPropertyName($php, $call->arguments, $field->value);
                if (null === $field || null === $property) {
                    continue;
                }
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::Property,
                    $dataClass.'::$'.$property,
                    $uri,
                    $this->converter->toRange($text, $field->startOffset, $field->endOffset - $field->startOffset),
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
            foreach ($php->literalArray($argument)->keys ?? [] as $key) {
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

    /** Late static binding hides the class the option resolves to, so only `self`, `parent` and named classes count. */
    private function earlyBoundClassName(string $source, ?PhpClassReference $reference): ?string
    {
        if (null === $reference) {
            return null;
        }
        $name = ClassNameKey::from(substr($source, $reference->startOffset, $reference->endOffset - $reference->startOffset));

        return 'static' === $name ? null : $reference->className;
    }

    /**
     * The entries of the array literal the argument holds, keyed by identifier
     * key, or null when a key or the array itself cannot be read statically.
     *
     * @return array<string, PhpLiteralArrayEntry>|null
     */
    private function arrayEntries(PhpDocument $php, ?PhpArgument $argument): ?array
    {
        $array = $php->literalArray($argument);
        if (null === $array || !$array->complete || $array->hasUnknownKeys) {
            return null;
        }
        $entries = [];
        foreach ($array->entries as $entry) {
            if (null === $entry->key || 1 !== preg_match(self::IDENTIFIER_PATTERN, $entry->key->value)) {
                return null;
            }
            $entries[$entry->key->value] = $entry;
        }

        return $entries;
    }

    private function identifierArgument(?PhpArgument $argument): ?PhpStringLiteral
    {
        $literal = $argument?->stringLiteral;

        return null !== $literal && 1 === preg_match(self::IDENTIFIER_PATTERN, $literal->value) ? $literal : null;
    }

    /** @param list<PhpArgument> $arguments */
    private function formPropertyName(PhpDocument $php, array $arguments, string $field): ?string
    {
        foreach (\array_slice($arguments, 1) as $argument) {
            if (null !== $argument->name) {
                return null;
            }
        }
        if (!isset($arguments[2])) {
            return $field;
        }
        $options = $this->arrayEntries($php, $arguments[2]);
        if (null === $options) {
            return null;
        }
        if (isset($options['mapped']) && true !== $options['mapped']->value?->scalarValue) {
            return null;
        }
        if (!isset($options['property_path'])) {
            return $field;
        }
        if (PhpLiteralKind::Null === $options['property_path']->value?->kind) {
            return $field;
        }
        $propertyPath = $options['property_path']->stringValue?->value;

        return null !== $propertyPath && 1 === preg_match(self::IDENTIFIER_PATTERN, $propertyPath) ? $propertyPath : null;
    }

    private function context(MetadataCompletionKind $kind, string $prefix, string $text, int $offset, ?string $owner = null): MetadataCompletionContext
    {
        return new MetadataCompletionContext($kind, $prefix, $this->converter->toRange($text, $offset, \strlen($prefix)), $owner);
    }
}
