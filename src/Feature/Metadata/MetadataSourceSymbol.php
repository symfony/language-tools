<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\RangedSourceSymbolInterface;

final class MetadataSourceSymbol implements RangedSourceSymbolInterface
{
    public function __construct(
        public readonly MetadataSymbolKind $kind,
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly bool $declaration,
        public readonly ?string $signature = null,
        public readonly ?string $description = null,
    ) {
    }
}
