<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\LocatedSourceSymbolInterface;
use Symfony\Lsp\Index\NamedSourceSymbolInterface;

final class AssetSourceSymbol implements LocatedSourceSymbolInterface, NamedSourceSymbolInterface
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
