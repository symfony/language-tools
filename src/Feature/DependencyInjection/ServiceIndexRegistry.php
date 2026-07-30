<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Project\Project;

final class ServiceIndexRegistry
{
    /** @var array<string, ServiceIndex> */
    private array $indexes = [];

    public function forProject(Project $project): ServiceIndex
    {
        return $this->indexes[$project->rootPath()] ??= new ServiceIndex();
    }
}
