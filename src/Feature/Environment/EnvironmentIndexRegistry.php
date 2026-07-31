<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Project\Project;

final class EnvironmentIndexRegistry
{
    /** @var array<string, EnvironmentIndex> */
    private array $indexes = [];

    public function forProject(Project $project): EnvironmentIndex
    {
        return $this->indexes[$project->rootPath()] ??= new EnvironmentIndex();
    }
}
