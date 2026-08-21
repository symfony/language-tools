<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Index\SourceFactsInterface;

final class RouteSourceFacts implements SourceFactsInterface
{
    /**
     * @param list<RouteDeclaration>       $declarations
     * @param list<RouteReferenceLocation> $references
     */
    public function __construct(
        private readonly string $uri,
        private readonly array $declarations,
        private readonly array $references,
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<RouteDeclaration> */
    public function declarations(): array
    {
        return $this->declarations;
    }

    /** @return list<RouteReferenceLocation> */
    public function references(): array
    {
        return $this->references;
    }

    public function isEmpty(): bool
    {
        return [] === $this->declarations && [] === $this->references;
    }
}
