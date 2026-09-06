<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Index\AbstractProjectIndexRegistry;
use Symfony\Lsp\Project\Project;

/** @extends AbstractProjectIndexRegistry<RouteSourceIndex> */
final class RouteSourceIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct(private readonly DependencyInjectionSourceIndexRegistry $classIndexes)
    {
        parent::__construct();
    }

    protected function createIndex(Project $project): RouteSourceIndex
    {
        return new RouteSourceIndex($this->classIndexes->forProject($project));
    }
}
