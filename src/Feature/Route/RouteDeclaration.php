<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Range;

final class RouteDeclaration
{
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
    ) {
    }
}
