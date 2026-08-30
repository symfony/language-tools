<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Range;

final class RouteSymbol
{
    public function __construct(
        public readonly string $name,
        public readonly Range $range,
    ) {
    }
}
