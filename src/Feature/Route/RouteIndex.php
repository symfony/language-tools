<?php

namespace Symfony\Lsp\Feature\Route;

final class RouteIndex
{
    /** @var array<string, Route> */
    private array $routes = [];

    public function replace(Route ...$routes): void
    {
        $this->routes = [];
        foreach ($routes as $route) {
            $this->routes[$route->name()] = $route;
        }
        ksort($this->routes);
    }

    /**
     * @return list<Route>
     */
    public function matching(string $prefix): array
    {
        return array_values(array_filter(
            $this->routes,
            static fn (Route $route): bool => str_starts_with($route->name(), $prefix),
        ));
    }

    public function get(string $name): ?Route
    {
        return $this->routes[$name] ?? null;
    }
}
