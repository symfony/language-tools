<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Project\Project;

final class RouteReferenceIndexRegistry
{
    /** @var array<string, RouteReferenceIndex> */
    private array $indexes = [];

    public function forProject(Project $project): RouteReferenceIndex
    {
        return $this->indexes[$project->rootPath()] ??= new RouteReferenceIndex();
    }
}
