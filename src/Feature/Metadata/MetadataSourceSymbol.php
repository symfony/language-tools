<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\Range;

final class MetadataSourceSymbol
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
