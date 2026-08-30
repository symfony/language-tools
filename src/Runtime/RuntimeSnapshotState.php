<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectStateInterface;

final class RuntimeSnapshotState implements ProjectStateInterface
{
    /** @var array<string, string> */
    private array $lastSuccessfulAt = [];

    public function markReady(Project $project): void
    {
        $this->lastSuccessfulAt[$project->rootPath] = gmdate(\DateTimeInterface::ATOM);
    }

    public function restore(Project $project, string $lastSuccessfulAt): void
    {
        $this->lastSuccessfulAt[$project->rootPath] = $lastSuccessfulAt;
    }

    public function has(Project $project): bool
    {
        return isset($this->lastSuccessfulAt[$project->rootPath]);
    }

    public function lastSuccessfulAt(Project $project): ?string
    {
        return $this->lastSuccessfulAt[$project->rootPath] ?? null;
    }

    public function removeProject(Project $project): void
    {
        unset($this->lastSuccessfulAt[$project->rootPath]);
    }
}
