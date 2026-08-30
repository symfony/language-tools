<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpDocument
{
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
    ) {
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

    public function firstClassReference(?PhpArgument $argument): ?PhpClassReference
    {
        return $this->classReferencesWithin($argument)[0] ?? null;
    }

    public function soleClassReference(?PhpArgument $argument): ?PhpClassReference
    {
        $references = $this->classReferencesWithin($argument);

        return 1 === \count($references) ? $references[0] : null;
    }

    public function firstObjectCreation(?PhpArgument $argument): ?PhpObjectCreation
    {
        $start = $argument?->expressionStartOffset;
        $end = $argument?->expressionEndOffset;
        if (!\is_int($start) || !\is_int($end)) {
            return null;
        }
        foreach ($this->objectCreations as $creation) {
            if ($creation->startOffset >= $start && $creation->endOffset <= $end) {
                return $creation;
            }
        }

        return null;
    }

    /**
     * Typed variables the call's receiver can resolve to, honoring scope:
     * a plain variable receiver matches parameters and promoted properties
     * of the enclosing scope, a `$this->property` receiver matches
     * properties and promoted properties of the enclosing class.
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
                && \in_array($variable->kind, [PhpTypedVariableKind::Parameter, PhpTypedVariableKind::PromotedProperty], true)
                && $call->scopeStartOffset === $variable->scopeStartOffset
            ) {
                $variables[] = $variable;
            } elseif (PhpMethodReceiverKind::ThisProperty === $receiver->kind
                && \in_array($variable->kind, [PhpTypedVariableKind::Property, PhpTypedVariableKind::PromotedProperty], true)
                && $call->className === $variable->className
            ) {
                $variables[] = $variable;
            }
        }

        return $variables;
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

    /** @return list<PhpClassReference> */
    private function classReferencesWithin(?PhpArgument $argument): array
    {
        $start = $argument?->expressionStartOffset;
        $end = $argument?->expressionEndOffset;
        if (!\is_int($start) || !\is_int($end)) {
            return [];
        }
        $references = [];
        foreach ($this->classReferences as $reference) {
            if ($reference->startOffset >= $start && $reference->endOffset <= $end) {
                $references[] = $reference;
            }
        }

        return $references;
    }
}
