<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Index\AbstractProjectIndexRegistry;
use Symfony\Lsp\Project\Project;

/** @extends AbstractProjectIndexRegistry<RouteReferenceIndex> */
final class RouteReferenceIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct(private readonly DependencyInjectionSourceIndexRegistry $classIndexes)
    {
        parent::__construct();
    }

    protected function createIndex(Project $project): RouteReferenceIndex
    {
        return new RouteReferenceIndex($this->classIndexes->forProject($project));
    }
}
