<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\RangedSourceSymbolInterface;

final class SecuritySourceSymbol implements RangedSourceSymbolInterface
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
