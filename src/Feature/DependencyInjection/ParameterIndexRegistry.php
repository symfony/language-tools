<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Project\Project;

final class ParameterIndexRegistry
{
    /** @var array<string, ParameterIndex> */
    private array $indexes = [];

    public function forProject(Project $project): ParameterIndex
    {
        return $this->indexes[$project->rootPath()] ??= new ParameterIndex();
    }
}
