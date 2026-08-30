<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Index\SourceFactsInterface;

final class AssetSourceFacts implements SourceFactsInterface
{
    /** @param list<AssetSourceSymbol> $symbols */
    public function __construct(public readonly string $uri, public readonly array $symbols)
    {
    }

    public function isEmpty(): bool
    {
        return [] === $this->symbols;
    }
}
