<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpDocument
{
    /** @var array<string, PhpMethodCall> */
    private readonly array $methodCallsByRange;
    private readonly PhpNameContext $names;

    /**
     * @param list<PhpAttribute>           $attributes
     * @param list<PhpMethodCall>          $methodCalls
     * @param list<PhpTypeDeclaration>     $typeDeclarations
     * @param list<PhpDiagnostic>          $diagnostics
     * @param list<PhpTypedVariable>       $typedVariables
     * @param list<PhpObjectCreation>      $objectCreations
     * @param list<PhpMethodDeclaration>   $methodDeclarations
     * @param list<PhpConstantDeclaration> $constantDeclarations
     * @param list<PhpPropertyDeclaration> $propertyDeclarations
     * @param list<PhpClassReference>      $classReferences
     * @param list<PhpLexicalScope>        $lexicalScopes
     * @param list<PhpLiteralArray>        $literalArrays
     */
    public function __construct(
        public readonly array $attributes,
        public readonly array $methodCalls,
        public readonly array $typeDeclarations,
        public readonly array $diagnostics,
        public readonly array $typedVariables = [],
        ?PhpNameContext $names = null,
        public readonly array $objectCreations = [],
        public readonly array $methodDeclarations = [],
        public readonly array $constantDeclarations = [],
        public readonly array $propertyDeclarations = [],
        public readonly array $classReferences = [],
        public readonly array $lexicalScopes = [],
        public readonly array $literalArrays = [],
    ) {
        $methodCallsByRange = [];
        foreach ($methodCalls as $call) {
            $methodCallsByRange[$call->startOffset.':'.$call->endOffset] ??= $call;
        }
        $this->methodCallsByRange = $methodCallsByRange;
        $this->names = $names ?? new PhpNameContext();
    }

    public function namespace(): string
    {
        return $this->names->namespace;
    }

    /** @return array<string, string> */
    public function imports(): array
    {
        return $this->names->imports;
    }

    public function resolveName(string $name): string
    {
        return $this->names->resolve($name);
    }

    public function firstObjectCreation(?PhpArgument $argument): ?PhpObjectCreation
    {
        return $this->objectCreationsWithin($argument)[0] ?? null;
    }

    public function literalArray(?PhpArgument $argument): ?PhpLiteralArray
    {
        $start = $argument?->expressionStartOffset;
        $end = $argument?->expressionEndOffset;
        if (!\is_int($start) || !\is_int($end)) {
            return null;
        }
        foreach ($this->literalArrays as $array) {
            if ($start === $array->startOffset && $end === $array->endOffset) {
                return $array;
            }
        }

        return null;
    }

    /**
     * Object creations the argument holds, without the ones nested in the
     * arguments of another creation.
     *
     * @return list<PhpObjectCreation>
     */
    public function objectCreationsWithin(?PhpArgument $argument): array
    {
        $start = $argument?->expressionStartOffset;
        $end = $argument?->expressionEndOffset;
        if (!\is_int($start) || !\is_int($end)) {
            return [];
        }
        $creations = [];
        foreach ($this->objectCreations as $creation) {
            if ($creation->startOffset < $start || $creation->endOffset > $end
                || array_any($creations, static fn (PhpObjectCreation $outer): bool => $creation->startOffset >= $outer->startOffset && $creation->endOffset <= $outer->endOffset)
            ) {
                continue;
            }
            $creations[] = $creation;
        }

        return $creations;
    }

    public function receiverCall(PhpMethodCall $call): ?PhpMethodCall
    {
        $receiver = $call->receiverContext;
        $candidate = $this->methodCallsByRange[$receiver->startOffset.':'.$receiver->endOffset] ?? null;

        return $call === $candidate ? null : $candidate;
    }

    /** @return list<PhpTypedVariable> */
    public function visibleVariables(int $offset): array
    {
        $scope = $this->lexicalScopeAt($offset);

        return array_values(array_filter(
            $this->typedVariables,
            fn (PhpTypedVariable $variable): bool => \in_array($variable->kind, [PhpTypedVariableKind::Parameter, PhpTypedVariableKind::PromotedProperty], true)
                && null !== $variable->scopeStartOffset
                && null !== $variable->scopeEndOffset
                && $offset >= $variable->scopeStartOffset
                && $offset <= $variable->scopeEndOffset
                && (null === $scope || $variable->scopeStartOffset === $scope->startOffset || $this->isVariableVisibleFromScope($variable->name, $variable->scopeStartOffset, $scope->startOffset)),
        ));
    }

    /**
     * The innermost typed parameter declaration containing the offset,
     * ignoring closure capture but honoring untyped parameter shadowing.
     */
    public function scopedVariable(int $offset, string $name): ?PhpTypedVariable
    {
        $parameterScopeStartOffset = -1;
        foreach ($this->lexicalScopes as $scope) {
            if ($offset >= $scope->startOffset && $offset <= $scope->endOffset && \in_array($name, $scope->parameterNames, true)) {
                $parameterScopeStartOffset = max($parameterScopeStartOffset, $scope->startOffset);
            }
        }

        $variable = null;
        foreach ($this->typedVariables as $candidate) {
            if ($name !== $candidate->name
                || !\in_array($candidate->kind, [PhpTypedVariableKind::Parameter, PhpTypedVariableKind::PromotedProperty], true)
                || null === $candidate->scopeStartOffset
                || null === $candidate->scopeEndOffset
                || $offset < $candidate->scopeStartOffset
                || $offset > $candidate->scopeEndOffset
                || null !== $variable && $candidate->scopeStartOffset < $variable->scopeStartOffset
            ) {
                continue;
            }
            $variable = $candidate;
        }

        if (null === $variable || null === $variable->scopeStartOffset || $parameterScopeStartOffset > $variable->scopeStartOffset) {
            return null;
        }

        return $variable;
    }

    /**
     * Typed variables the call's receiver can resolve to, honoring direct and
     * nested lexical scope boundaries.
     *
     * @return list<PhpTypedVariable>
     */
    public function receiverVariables(PhpMethodCall $call): array
    {
        $receiver = $call->receiverContext;
        if (null === $receiver->name) {
            return [];
        }
        $variables = [];
        foreach ($this->typedVariables as $variable) {
            if ($receiver->name !== $variable->name) {
                continue;
            }
            if (PhpMethodReceiverKind::Variable === $receiver->kind
                && (\in_array($variable->kind, [PhpTypedVariableKind::Parameter, PhpTypedVariableKind::PromotedProperty], true)
                    && $call->scopeStartOffset === $variable->scopeStartOffset
                    || PhpTypedVariableKind::Parameter === $variable->kind
                    && \is_int($variable->scopeStartOffset)
                    && $this->isVariableVisible($variable->name, $variable->scopeStartOffset, $call))
            ) {
                $variables[] = $variable;
            } elseif (PhpMethodReceiverKind::ThisProperty === $receiver->kind
                && null !== $call->className
                && \in_array($variable->kind, [PhpTypedVariableKind::Property, PhpTypedVariableKind::PromotedProperty], true)
                && $call->className === $variable->className
            ) {
                $variables[] = $variable;
            }
        }

        return $variables;
    }

    public function receiverHasType(PhpMethodCall $call, string ...$types): bool
    {
        return array_any($this->receiverVariables($call), static fn (PhpTypedVariable $variable): bool => [] !== array_intersect($types, $variable->types));
    }

    public function isVariableVisible(string $name, int $declarationScopeStartOffset, PhpMethodCall $call): bool
    {
        return \is_int($call->scopeStartOffset) && $this->isVariableVisibleFromScope($name, $declarationScopeStartOffset, $call->scopeStartOffset);
    }

    /** @return list<PhpAttribute> */
    public function attributesNamed(string $name): array
    {
        return array_values(array_filter($this->attributes, static fn (PhpAttribute $attribute): bool => $name === $attribute->name));
    }

    /** @return list<PhpAttribute> */
    public function attributesOn(PhpAttributeTargetKind $kind, string $className, ?string $memberName = null): array
    {
        $attributes = [];
        foreach ($this->attributes as $attribute) {
            foreach ($attribute->targets as $target) {
                if ($kind === $target->kind && $className === $target->className && (null === $memberName || $memberName === $target->memberName)) {
                    $attributes[] = $attribute;
                    break;
                }
            }
        }

        return $attributes;
    }

    private function isVariableVisibleFromScope(string $name, int $declarationScopeStartOffset, int $scopeStartOffset): bool
    {
        while ($scopeStartOffset !== $declarationScopeStartOffset) {
            $scope = $this->lexicalScopeStartingAt($scopeStartOffset);
            if (null === $scope || !$scope->captureComplete || \in_array($name, $scope->parameterNames, true)) {
                return false;
            }
            if (PhpLexicalScopeKind::Closure === $scope->kind && !\in_array($name, $scope->capturedVariableNames, true)) {
                return false;
            }
            if (null === $scopeStartOffset = $scope->parentScopeStartOffset) {
                return false;
            }
        }

        return true;
    }

    private function lexicalScopeAt(int $offset): ?PhpLexicalScope
    {
        $innermost = null;
        foreach ($this->lexicalScopes as $scope) {
            if ($offset >= $scope->startOffset && $offset <= $scope->endOffset && (null === $innermost || $scope->startOffset > $innermost->startOffset)) {
                $innermost = $scope;
            }
        }

        return $innermost;
    }

    private function lexicalScopeStartingAt(int $startOffset): ?PhpLexicalScope
    {
        foreach ($this->lexicalScopes as $scope) {
            if ($startOffset === $scope->startOffset) {
                return $scope;
            }
        }

        return null;
    }
}
