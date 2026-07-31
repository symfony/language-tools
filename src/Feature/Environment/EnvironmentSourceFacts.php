<?php

namespace Symfony\Lsp\Feature\Environment;

final class EnvironmentSourceFacts
{
    /**
     * @param list<EnvironmentDeclaration> $declarations
     * @param list<EnvironmentReference>   $references
     */
    public function __construct(
        private readonly string $uri,
        private readonly array $declarations = [],
        private readonly array $references = [],
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<EnvironmentDeclaration> */
    public function declarations(): array
    {
        return $this->declarations;
    }

    /** @return list<EnvironmentReference> */
    public function references(): array
    {
        return $this->references;
    }
}
