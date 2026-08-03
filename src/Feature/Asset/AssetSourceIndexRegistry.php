<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Project\Project;

final class AssetSourceIndexRegistry
{
    /** @var array<string, AssetSourceIndex> */
    private array $indexes = [];

    public function forProject(Project $project): AssetSourceIndex
    {
        return $this->indexes[$project->rootPath()] ??= new AssetSourceIndex();
    }
}
