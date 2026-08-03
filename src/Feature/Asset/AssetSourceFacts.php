<?php

namespace Symfony\Lsp\Feature\Asset;

final class AssetSourceFacts
{
    /** @param list<AssetSourceSymbol> $symbols */
    public function __construct(private readonly string $uri, private readonly array $symbols)
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<AssetSourceSymbol> */
    public function symbols(): array
    {
        return $this->symbols;
    }
}
