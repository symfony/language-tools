<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Range;

final class RouteReferenceLocation
{
    /** @param list<string>|null $providedParameters */
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly ?string $controllerClass = null,
        public readonly ?array $providedParameters = null,
    ) {
    }
}
