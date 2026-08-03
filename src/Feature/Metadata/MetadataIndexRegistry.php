<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Project\Project;

final class MetadataIndexRegistry
{
    /** @var array<string, MetadataIndex> */
    private array $indexes = [];

    public function forProject(Project $project): MetadataIndex
    {
        return $this->indexes[$project->rootPath()] ??= new MetadataIndex();
    }
}
