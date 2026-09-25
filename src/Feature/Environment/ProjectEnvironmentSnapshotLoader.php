<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\SnapshotSection;

final class ProjectEnvironmentSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly EnvironmentIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'environment';
    }

    public function load(Project $project, SnapshotSection $section): void
    {
        $processors = [];
        foreach ($section->items('processors', 'name', 'type') as $processor) {
            $processors[$processor->string('name')] = $processor->string('type');
        }
        $this->indexes->forProject($project)->replaceProcessors($processors, $section->complete());
    }
}
