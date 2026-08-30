<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Range;

final class RouteReference
{
    /**
     * @param list<string>|null $providedParameters
     */
    public function __construct(
        public readonly string $name,
        public readonly Range $range,
        public readonly ?array $providedParameters = null,
        public readonly ?string $controllerClass = null,
    ) {
    }
}
