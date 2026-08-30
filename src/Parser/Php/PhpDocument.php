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
}
