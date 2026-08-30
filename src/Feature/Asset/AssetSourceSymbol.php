<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Document\Range;

final class AssetSourceSymbol
{
    public function __construct(
        public readonly AssetSymbolKind $kind,
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly bool $declaration,
    ) {
    }
}
