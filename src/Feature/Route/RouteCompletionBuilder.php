<?php

namespace Symfony\Lsp\Feature\Route;

final class RouteCompletionBuilder
{
    /**
     * @return list<array{label: string, kind: int, detail: string}>
     */
    public function complete(RouteIndex $routeIndex, string $prefix): array
    {
        return array_map(
            static fn (Route $route): array => [
                'label' => $route->name(),
                'kind' => 12,
                'detail' => $route->path() ?? 'Symfony route',
            ],
            $routeIndex->matching($prefix),
        );
    }
}
