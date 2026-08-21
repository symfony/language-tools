<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Index\SourceFactsInterface;

final class AssetSourceFacts implements SourceFactsInterface
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

    public function isEmpty(): bool
    {
        return [] === $this->symbols;
    }
}
