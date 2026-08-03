<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Document\Range;

final class AssetCompletionContext
{
    public function __construct(
        private readonly AssetSymbolKind $kind,
        private readonly string $prefix,
        private readonly Range $range,
    ) {
    }

    public function kind(): AssetSymbolKind
    {
        return $this->kind;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function range(): Range
    {
        return $this->range;
    }
}
