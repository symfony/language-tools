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
        public readonly string $uri,
        public readonly array $declarations,
        public readonly array $references,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->declarations && [] === $this->references;
    }
}
