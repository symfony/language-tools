<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Index\SourceFactsInterface;

final class DoctrineSourceFacts implements SourceFactsInterface
{
    /**
     * @param list<DoctrineEntity>       $entities
     * @param list<DoctrineRepository>   $repositories
     * @param list<DoctrineSourceSymbol> $symbols
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $entities,
        public readonly array $repositories,
        public readonly array $symbols,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->entities && [] === $this->repositories && [] === $this->symbols;
    }
}
