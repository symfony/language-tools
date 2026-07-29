<?php

namespace Symfony\Lsp\Project;

final class WorkspaceTrust
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
}
