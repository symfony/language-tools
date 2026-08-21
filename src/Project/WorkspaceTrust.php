<?php

namespace Symfony\Lsp\Project;

final class WorkspaceTrust implements ProjectStateInterface
{
    /** @var array<string, TrustStatus> */
    private array $statuses = [];

    public function set(Project $project, TrustStatus $status): void
    {
        $this->statuses[$project->rootPath()] = $status;
    }

    public function status(Project $project): TrustStatus
    {
        return $this->statuses[$project->rootPath()] ?? TrustStatus::Unknown;
    }

    public function removeProject(Project $project): void
    {
        unset($this->statuses[$project->rootPath()]);
    }
}
