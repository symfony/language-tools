<?php

namespace Symfony\Lsp\Parser\Php;

final class TolerantPhpDeclarationFacts
{
    /**
     * @param list<PhpAttribute>           $attributes
     * @param list<PhpTypeDeclaration>     $typeDeclarations
     * @param list<PhpTypedVariable>       $typedVariables
     * @param list<PhpMethodDeclaration>   $methodDeclarations
     * @param list<PhpConstantDeclaration> $constantDeclarations
     * @param list<PhpPropertyDeclaration> $propertyDeclarations
     */
    public function __construct(
        public readonly array $attributes,
        public readonly array $typeDeclarations,
        public readonly array $typedVariables,
        public readonly array $methodDeclarations,
        public readonly array $constantDeclarations,
        public readonly array $propertyDeclarations,
    ) {
    }
}
