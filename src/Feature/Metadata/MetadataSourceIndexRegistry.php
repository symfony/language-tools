<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Project\Project;

final class MetadataSourceIndexRegistry
{
    /** @var array<string, MetadataSourceIndex> */
    private array $indexes = [];

    public function forProject(Project $project): MetadataSourceIndex
    {
        return $this->indexes[$project->rootPath()] ??= new MetadataSourceIndex();
    }
}
