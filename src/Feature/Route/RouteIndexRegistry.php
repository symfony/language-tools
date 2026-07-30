<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Project\Project;

final class RouteIndexRegistry
{
    /** @var array<string, RouteIndex> */
    private array $indexes = [];

    public function forProject(Project $project): RouteIndex
    {
        return $this->indexes[$project->rootPath()] ??= new RouteIndex();
    }
}
