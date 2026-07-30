<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ProjectServiceSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(
        private readonly ServiceIndexRegistry $indexes,
    ) {
    }

    public function section(): string
    {
        return 'container';
    }

    public function load(Project $project, array $snapshot): void
    {
        $sections = $snapshot['sections'] ?? null;
        $container = \is_array($sections) ? ($sections['container'] ?? null) : null;
        $items = \is_array($container) ? ($container['items'] ?? null) : null;
        if (!\is_array($items)) {
            return;
        }

        $services = [];
        foreach ($items as $item) {
            if (!\is_array($item) || !\is_string($item['id'] ?? null)) {
                continue;
            }

            $services[] = new Service(
                $item['id'],
                \is_string($item['class'] ?? null) ? $item['class'] : null,
                \is_string($item['alias'] ?? null) ? $item['alias'] : null,
                \is_bool($item['public'] ?? null) ? $item['public'] : null,
                \is_bool($item['lazy'] ?? null) ? $item['lazy'] : null,
                \is_string($item['deprecation'] ?? null) ? $item['deprecation'] : null,
                $this->strings($item['tags'] ?? null),
                \is_string($item['decorates'] ?? null) ? $item['decorates'] : null,
                $this->strings($item['autowiringTypes'] ?? null),
            );
        }

        $this->indexes->forProject($project)->replace(
            true === ($container['complete'] ?? null),
            ...$services,
        );
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        return \is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }
}
