<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Runtime\RuntimeSnapshotValues;

final class RouteSnapshotImporter
{
    public function __construct(
        private readonly RouteIndex $routeIndex,
    ) {
    }

    /**
     * @param array<array-key, mixed> $section
     */
    public function load(array $section): void
    {
        $items = $section['items'] ?? null;
        if (!\is_array($items) || true !== ($section['complete'] ?? null)) {
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
                RuntimeSnapshotValues::stringList($item['methods'] ?? null),
                RuntimeSnapshotValues::stringList($item['schemes'] ?? null),
                \is_string($item['host'] ?? null) ? $item['host'] : null,
                \is_string($item['controller'] ?? null) ? $item['controller'] : null,
                RuntimeSnapshotValues::stringList($item['defaults'] ?? null),
                $this->stringMap($item['requirements'] ?? null),
                \is_string($item['alias'] ?? null) ? $item['alias'] : null,
                \is_string($item['canonical'] ?? null) ? $item['canonical'] : null,
            );
        }

        $this->routeIndex->replaceRuntime(
            RuntimeSnapshotValues::stringList($section['resources'] ?? null),
            RuntimeSnapshotValues::stringList($section['contextParameters'] ?? null),
            ...$routes,
        );
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
