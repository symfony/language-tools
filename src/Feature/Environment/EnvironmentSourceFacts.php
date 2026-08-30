<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Index\SourceFactsInterface;

final class EnvironmentSourceFacts implements SourceFactsInterface
{
    /**
     * @param list<EnvironmentDeclaration> $declarations
     * @param list<EnvironmentReference>   $references
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $declarations = [],
        public readonly array $references = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->declarations && [] === $this->references;
    }
}
