<?php

namespace Symfony\Lsp\Feature\Route;

final class RouteIndex
{
    /** @var array<string, Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $completionRoutes = [];

    private bool $complete = false;

    public function replace(Route ...$routes): void
    {
        $this->routes = [];
        $this->completionRoutes = [];
        foreach ($routes as $route) {
            $this->routes[$route->name()] = $route;
            $completionName = $route->canonicalName() ?? $route->name();
            $this->routes[$completionName] ??= $route;
            if ($completionName === $route->name() || !isset($this->completionRoutes[$completionName])) {
                $this->completionRoutes[$completionName] = $route;
            }
        }
        ksort($this->routes);
        ksort($this->completionRoutes);
        $this->complete = true;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * @return list<Route>
     */
    public function matching(string $prefix): array
    {
        $routes = [];
        foreach ($this->completionRoutes as $name => $route) {
            if (str_starts_with($name, $prefix)) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    public function get(string $name): ?Route
    {
        return $this->routes[$name] ?? null;
    }
}
