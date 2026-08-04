<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Project\Project;

final class StimulusSourceIndexRegistry
{
    /** @var array<string, StimulusSourceIndex> */
    private array $indexes = [];

    public function forProject(Project $project): StimulusSourceIndex
    {
        return $this->indexes[$project->rootPath()] ??= new StimulusSourceIndex();
    }
}
