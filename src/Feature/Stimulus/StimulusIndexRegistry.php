<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Project\Project;

final class StimulusIndexRegistry
{
    /** @var array<string, StimulusIndex> */
    private array $indexes = [];

    public function forProject(Project $project): StimulusIndex
    {
        return $this->indexes[$project->rootPath()] ??= new StimulusIndex();
    }
}
