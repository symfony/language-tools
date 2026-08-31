<?php

namespace Symfony\Lsp\Parser\Php;

final class TolerantPhpExpressionFacts
{
    /**
     * @param list<PhpMethodCall>     $methodCalls
     * @param list<PhpObjectCreation> $objectCreations
     * @param list<PhpClassReference> $classReferences
     */
    public function __construct(
        public readonly array $methodCalls,
        public readonly array $objectCreations,
        public readonly array $classReferences,
    ) {
    }
}
