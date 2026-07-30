<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Project\Project;

final class DependencyInjectionSourceIndexRegistry
{
    /** @var array<string, DependencyInjectionSourceIndex> */
    private array $indexes = [];

    public function forProject(Project $project): DependencyInjectionSourceIndex
    {
        return $this->indexes[$project->rootPath()] ??= new DependencyInjectionSourceIndex();
    }
}
