<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Document\Range;

final class SecuritySourceSymbol
{
    public function __construct(
        public readonly SecuritySymbolKind $kind,
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly bool $declaration,
    ) {
    }
}
