<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ProjectRouteSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(
        private readonly RouteIndexRegistry $indexes,
    ) {
    }

    public function section(): string
    {
        return 'routes';
    }

    public function load(Project $project, array $snapshot): void
    {
        (new RouteSnapshotLoader($this->indexes->forProject($project)))->load($snapshot);
    }
}
