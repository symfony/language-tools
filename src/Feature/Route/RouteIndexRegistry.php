<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Index\AbstractProjectIndexRegistry;

/** @extends AbstractProjectIndexRegistry<RouteIndex> */
final class RouteIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(RouteIndex::class);
    }
}
