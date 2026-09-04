<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Range;

final class DependencyInjectionReference
{
    public function __construct(
        public readonly DependencyInjectionSymbolKind $kind,
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly bool $optional = false,
        public readonly ?string $environment = null,
    ) {
    }
}
