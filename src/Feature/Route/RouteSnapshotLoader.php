<?php

namespace Symfony\Lsp\Feature\Route;

final class RouteSnapshotLoader
{
    public function __construct(
        private readonly RouteIndex $routeIndex,
    ) {
    }

    /**
     * @param array<array-key, mixed> $snapshot
     */
    public function load(array $snapshot): void
    {
        $sections = $snapshot['sections'] ?? null;
        $routesSection = \is_array($sections) ? ($sections['routes'] ?? null) : null;
        $items = \is_array($routesSection) ? ($routesSection['items'] ?? null) : null;
        if (!\is_array($items) || true !== ($routesSection['complete'] ?? null)) {
            return;
        }

        $routes = [];
        foreach ($items as $item) {
            if (!\is_array($item) || !\is_string($item['name'] ?? null)) {
                continue;
            }

            $routes[] = new Route(
                $item['name'],
                \is_string($item['path'] ?? null) ? $item['path'] : null,
                $this->strings($item['methods'] ?? null),
                $this->strings($item['schemes'] ?? null),
                \is_string($item['host'] ?? null) ? $item['host'] : null,
                \is_string($item['controller'] ?? null) ? $item['controller'] : null,
                $this->strings($item['defaults'] ?? null),
                $this->stringMap($item['requirements'] ?? null),
                \is_string($item['alias'] ?? null) ? $item['alias'] : null,
            );
        }

        $this->routeIndex->replace(...$routes);
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, 'is_string'));
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
