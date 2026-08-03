<?php

namespace Symfony\Lsp\Feature\Metadata;

final class MetadataSourceFacts
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
}
