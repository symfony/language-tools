<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Range;

final class RouteParameterCompletionContext
{
    /**
     * @param list<string> $existingParameters
     */
    public function __construct(
        public readonly string $routeName,
        public readonly string $prefix,
        public readonly Range $replacementRange,
        public readonly array $existingParameters,
    ) {
    }
}
