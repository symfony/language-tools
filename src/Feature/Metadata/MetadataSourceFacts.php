<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Index\SourceFactsInterface;

final class MetadataSourceFacts implements SourceFactsInterface
{
    /** @param list<MetadataSourceSymbol> $symbols */
    public function __construct(private readonly string $uri, private readonly array $symbols)
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<MetadataSourceSymbol> */
    public function symbols(): array
    {
        return $this->symbols;
    }

    public function isEmpty(): bool
    {
        return [] === $this->symbols;
    }
}
