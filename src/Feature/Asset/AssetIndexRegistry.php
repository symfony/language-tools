<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Project\Project;

final class AssetIndexRegistry
{
    /** @var array<string, AssetIndex> */
    private array $indexes = [];

    public function forProject(Project $project): AssetIndex
    {
        return $this->indexes[$project->rootPath()] ??= new AssetIndex();
    }
}
