<?php

namespace Symfony\Lsp\Feature\Doctrine;

final class DoctrineSourceFacts
{
    /**
     * @param list<DoctrineEntity>       $entities
     * @param list<DoctrineRepository>   $repositories
     * @param list<DoctrineSourceSymbol> $symbols
     */
    public function __construct(
        private readonly string $uri,
        private readonly array $entities,
        private readonly array $repositories,
        private readonly array $symbols,
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<DoctrineEntity> */
    public function entities(): array
    {
        return $this->entities;
    }

    /** @return list<DoctrineRepository> */
    public function repositories(): array
    {
        return $this->repositories;
    }

    /** @return list<DoctrineSourceSymbol> */
    public function symbols(): array
    {
        return $this->symbols;
    }
}
