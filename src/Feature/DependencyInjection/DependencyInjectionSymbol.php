<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Range;

final class DependencyInjectionSymbol
{
    public function __construct(
        public readonly DependencyInjectionSymbolKind $kind,
        public readonly string $name,
        public readonly Range $range,
    ) {
    }
}
