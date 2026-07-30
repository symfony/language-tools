<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\Project;

final class RuntimeSnapshotLoaderRegistry
{
    /** @var list<RuntimeSnapshotLoaderInterface> */
    private array $loaders;

    public function __construct(RuntimeSnapshotLoaderInterface ...$loaders)
    {
        $this->loaders = array_values($loaders);
    }

    /**
     * @return list<string>
     */
    public function sections(): array
    {
        return array_values(array_unique(array_map(
            static fn (RuntimeSnapshotLoaderInterface $loader): string => $loader->section(),
            $this->loaders,
        )));
    }

    /**
     * @param array<array-key, mixed> $snapshot
     */
    public function load(Project $project, array $snapshot): void
    {
        foreach ($this->loaders as $loader) {
            $loader->load($project, $snapshot);
        }
    }
}
