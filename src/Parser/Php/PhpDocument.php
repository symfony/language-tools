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
        private readonly array $attributes,
        private readonly array $methodCalls,
        private readonly array $typeDeclarations,
        private readonly array $diagnostics,
        private readonly array $typedVariables = [],
        ?PhpNameContext $names = null,
        private readonly array $objectCreations = [],
        private readonly array $methodDeclarations = [],
        private readonly array $constantDeclarations = [],
        private readonly array $propertyDeclarations = [],
        private readonly array $classReferences = [],
    ) {
        $this->names = $names ?? new PhpNameContext();
    }

    /** @return list<PhpAttribute> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /** @return list<PhpMethodCall> */
    public function methodCalls(): array
    {
        return $this->methodCalls;
    }

    /** @return list<PhpTypeDeclaration> */
    public function typeDeclarations(): array
    {
        return $this->typeDeclarations;
    }

    /** @return list<PhpObjectCreation> */
    public function objectCreations(): array
    {
        return $this->objectCreations;
    }

    /** @return list<PhpMethodDeclaration> */
    public function methodDeclarations(): array
    {
        return $this->methodDeclarations;
    }

    /** @return list<PhpConstantDeclaration> */
    public function constantDeclarations(): array
    {
        return $this->constantDeclarations;
    }

    /** @return list<PhpPropertyDeclaration> */
    public function propertyDeclarations(): array
    {
        return $this->propertyDeclarations;
    }

    /** @return list<PhpClassReference> */
    public function classReferences(): array
    {
        return $this->classReferences;
    }

    /** @return list<PhpDiagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /** @return list<PhpTypedVariable> */
    public function typedVariables(): array
    {
        return $this->typedVariables;
    }

    public function namespace(): string
    {
        return $this->names->namespace();
    }

    /** @return array<string, string> */
    public function imports(): array
    {
        return $this->names->imports();
    }

    public function resolveName(string $name): string
    {
        return $this->names->resolve($name);
    }
}
