<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectStateInterface;

final class ProjectIndexStatusRegistry implements ProjectStateInterface
{
    /** @var array<string, array{source: array{state: string, error?: string}, runtime: array{state: string, error?: string}}> */
    private array $statuses = [];

    /** @var array<string, true> */
    private array $hasRuntimeSnapshot = [];

    public function sourceIndexing(Project $project): void
    {
        $this->section($project, 'source', 'indexing');
    }

    public function sourceReady(Project $project): void
    {
        $this->section($project, 'source', 'ready');
    }

    public function sourceFailed(Project $project): void
    {
        $this->section($project, 'source', 'failed', 'Source indexing failed.');
    }

    public function runtimeIndexing(Project $project): void
    {
        $this->section($project, 'runtime', 'indexing');
    }

    public function runtimeReady(Project $project): void
    {
        $this->hasRuntimeSnapshot[$project->rootPath()] = true;
        $this->section($project, 'runtime', 'ready');
    }

    public function runtimeStale(Project $project): void
    {
        $this->section($project, 'runtime', 'stale');
    }

    public function runtimeFailed(Project $project): void
    {
        $state = isset($this->hasRuntimeSnapshot[$project->rootPath()]) ? 'stale' : 'failed';
        $this->section($project, 'runtime', $state, 'Runtime indexing failed.');
    }

    public function removeProject(Project $project): void
    {
        unset($this->statuses[$project->rootPath()], $this->hasRuntimeSnapshot[$project->rootPath()]);
    }

    /**
     * @return array{root: string, source: array{state: string, error?: string}, runtime: array{state: string, error?: string}}
     */
    public function status(Project $project): array
    {
        $status = $this->statuses[$project->rootPath()] ?? [
            'source' => ['state' => 'not-indexed'],
            'runtime' => ['state' => 'not-indexed'],
        ];

        return ['root' => $project->rootPath(), ...$status];
    }

    /**
     * @param 'source'|'runtime' $section
     */
    private function section(Project $project, string $section, string $state, ?string $error = null): void
    {
        $status = $this->status($project);
        unset($status['root']);
        $status[$section] = ['state' => $state];
        if (null !== $error && '' !== $error) {
            $status[$section]['error'] = $error;
        }

        $this->statuses[$project->rootPath()] = $status;
    }
}
