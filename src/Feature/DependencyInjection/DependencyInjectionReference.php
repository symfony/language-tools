<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\LocatedSourceSymbolInterface;

final class DependencyInjectionReference implements LocatedSourceSymbolInterface
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
