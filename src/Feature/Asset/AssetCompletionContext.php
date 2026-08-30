<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Document\Range;

final class AssetCompletionContext
{
    public function __construct(
        public readonly AssetSymbolKind $kind,
        public readonly string $prefix,
        public readonly Range $range,
    ) {
    }
}
