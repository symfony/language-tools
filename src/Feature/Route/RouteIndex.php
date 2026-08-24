<?php

namespace Symfony\Lsp\Feature\Route;

final class RouteIndex
{
    /** @var array<string, Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $completionRoutes = [];

    /** @var array<string, true> */
    private array $resources = [];

    private bool $complete = false;

    public function replace(Route ...$routes): void
    {
        $this->replaceRuntime([], ...$routes);
    }

    /** @param list<string> $resources */
    public function replaceRuntime(array $resources, Route ...$routes): void
    {
        $this->routes = [];
        $this->completionRoutes = [];
        $this->resources = array_fill_keys($resources, true);
        $localizedRoutes = [];
        foreach ($routes as $route) {
            $this->routes[$route->name()] = $route;
            if (null === $canonicalName = $route->canonicalName()) {
                $this->completionRoutes[$route->name()] = $route;
            } else {
                $localizedRoutes[$canonicalName][] = $route;
            }
        }
        foreach ($localizedRoutes as $canonicalName => $variants) {
            $route = $this->aggregate($canonicalName, $variants);
            $this->routes[$canonicalName] = $route;
            $this->completionRoutes[$canonicalName] = $route;
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

    public function isResource(string $relativePath): bool
    {
        return isset($this->resources[$relativePath]);
    }

    /**
     * @param non-empty-list<Route> $variants
     */
    private function aggregate(string $name, array $variants): Route
    {
        $first = $variants[0];
        $path = $first->path();
        $host = $first->host();
        $controller = $first->controller();
        $alias = $first->alias();
        $methods = [];
        $schemes = [];
        $defaults = [];
        $requirements = $first->requirements();
        $parameters = [];
        $requiredParameters = $first->requiredParameters();
        foreach ($variants as $variant) {
            if ($path !== $variant->path()) {
                $path = null;
            }
            if ($host !== $variant->host()) {
                $host = null;
            }
            if ($controller !== $variant->controller()) {
                $controller = null;
            }
            if ($alias !== $variant->alias()) {
                $alias = null;
            }
            $methods = array_merge($methods, $variant->methods());
            $schemes = array_merge($schemes, $variant->schemes());
            $defaults = array_merge($defaults, $variant->defaults());
            $requirements = array_intersect_assoc($requirements, $variant->requirements());
            $parameters = array_merge($parameters, $variant->parameters());
            $requiredParameters = array_intersect($requiredParameters, $variant->requiredParameters());
        }
        $methods = array_values(array_unique($methods));
        $schemes = array_values(array_unique($schemes));
        $defaults = array_values(array_unique($defaults));
        $parameters = array_values(array_unique($parameters));
        $requiredParameters = array_values($requiredParameters);
        sort($methods);
        sort($schemes);
        sort($defaults);
        sort($parameters);
        sort($requiredParameters);

        return new Route(
            $name,
            $path,
            $methods,
            $schemes,
            $host,
            $controller,
            $defaults,
            $requirements,
            $alias,
            parameters: $parameters,
            requiredParameters: $requiredParameters,
        );
    }
}
