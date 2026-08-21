<?php

namespace Symfony\Lsp\Project;

final class ProjectStateCleaner
{
    /** @param iterable<ProjectStateInterface> $states */
    public function __construct(private readonly iterable $states)
    {
    }

    public function remove(Project $project): void
    {
        foreach ($this->states as $state) {
            $state->removeProject($project);
        }
    }
}
