<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\LocatedSourceSymbolInterface;

final class RouteDeclaration implements LocatedSourceSymbolInterface
{
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
    ) {
    }
}
