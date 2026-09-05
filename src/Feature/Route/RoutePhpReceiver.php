<?php

namespace Symfony\Lsp\Feature\Route;

final class RoutePhpReceiver
{
    public function __construct(
        public readonly ?string $controllerClass,
    ) {
    }
}
