<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Project\Project;

final class RouteDeclarationIndexRegistry
{
    /** @var array<string, RouteDeclarationIndex> */
    private array $indexes = [];

    public function forProject(Project $project): RouteDeclarationIndex
    {
        return $this->indexes[$project->rootPath()] ??= new RouteDeclarationIndex();
    }
}
