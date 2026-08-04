<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Project\Project;

final class DoctrineIndexRegistry
{
    /** @var array<string, DoctrineIndex> */
    private array $indexes = [];

    public function forProject(Project $project): DoctrineIndex
    {
        return $this->indexes[$project->rootPath()] ??= new DoctrineIndex();
    }
}
