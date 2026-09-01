<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\Project;

final class RuntimeSnapshotLoaderRegistry
{
    /** @param iterable<RuntimeSnapshotLoaderInterface> $loaders */
    public function __construct(private readonly iterable $loaders)
    {
    }

    /**
     * @return list<string>
     */
    public function sections(): array
    {
        $sections = [];
        foreach ($this->loaders as $loader) {
            $sections[] = $loader->section();
        }

        return array_values(array_unique($sections));
    }

    /**
     * @param array<array-key, mixed> $snapshot
     */
    public function load(Project $project, array $snapshot): void
    {
        $sections = $snapshot['sections'] ?? null;
        if (!\is_array($sections)) {
            return;
        }

        foreach ($this->loaders as $loader) {
            $section = $sections[$loader->section()] ?? null;
            if (!\is_array($section)) {
                continue;
            }
            $loader->load($project, $section);
        }
    }
}
