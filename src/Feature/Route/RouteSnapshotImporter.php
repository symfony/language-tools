<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Runtime\SnapshotSection;

final class RouteSnapshotImporter
{
    public function __construct(
        private readonly RouteIndex $routeIndex,
    ) {
    }

    public function load(SnapshotSection $section): void
    {
        // the index reports every replaced route set as complete, so an
        // incomplete one would turn unknown route names into diagnostics
        if (!$section->complete()) {
            return;
        }

        $routes = [];
        foreach ($section->items('items', 'name') as $item) {
            $routes[] = new Route(
                $item->string('name'),
                $item->optionalString('path'),
                $item->strings('methods'),
                $item->strings('schemes'),
                $item->optionalString('host'),
                $item->optionalString('controller'),
                $item->strings('defaults'),
                $item->stringMap('requirements'),
                $item->optionalString('alias'),
                $item->optionalString('canonical'),
            );
        }

        $this->routeIndex->replaceRuntime(
            $section->strings('resources'),
            $section->strings('contextParameters'),
            ...$routes,
        );
    }
}
