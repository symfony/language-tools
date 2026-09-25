<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\SnapshotSection;

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

    public function load(Project $project, SnapshotSection $section): void
    {
        (new RouteSnapshotImporter($this->indexes->forProject($project)))->load($section);
    }
}
