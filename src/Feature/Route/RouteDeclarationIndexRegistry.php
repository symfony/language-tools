<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Index\AbstractProjectIndexRegistry;

/** @extends AbstractProjectIndexRegistry<RouteDeclarationIndex> */
final class RouteDeclarationIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(RouteDeclarationIndex::class);
    }
}
