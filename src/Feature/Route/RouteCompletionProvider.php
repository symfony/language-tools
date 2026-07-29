<?php

namespace Symfony\Lsp\Feature\Route;

final class RouteCompletionProvider
{
    public function __construct(
        private readonly RouteIndex $routeIndex,
    ) {
    }

    /**
     * @return list<array{label: string, kind: int, detail: string}>
     */
    public function complete(string $prefix): array
    {
        return array_map(
            static fn (Route $route): array => [
                'label' => $route->name(),
                'kind' => 12,
                'detail' => $route->path() ?? 'Symfony route',
            ],
            $this->routeIndex->matching($prefix),
        );
    }
}
