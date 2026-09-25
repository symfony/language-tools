<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Range;

final class RouteCompletionContext
{
    public function __construct(
        public readonly string $prefix,
        public readonly Range $replacementRange,
    ) {
    }
}
