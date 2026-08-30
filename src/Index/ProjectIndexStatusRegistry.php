<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectStateInterface;
use Symfony\Lsp\Runtime\RuntimeSnapshotState;

final class ProjectIndexStatusRegistry implements ProjectStateInterface
{
    /** @var array<string, array{source: array{state: string, error?: string}, runtime: array{state: string, error?: string, stage?: string, lastSuccessfulAt?: string}}> */
    private array $statuses = [];

    private readonly RuntimeSnapshotState $runtimeSnapshots;

    public function __construct(?RuntimeSnapshotState $runtimeSnapshots = null)
    {
        $this->runtimeSnapshots = $runtimeSnapshots ?? new RuntimeSnapshotState();
    }

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
        $this->runtimeSnapshots->markReady($project);
        $this->section($project, 'runtime', 'ready');
    }

    public function runtimeStale(Project $project): void
    {
        $this->section($project, 'runtime', 'stale');
    }

    /**
     * @param 'bootstrap'|'configuration'|null $stage
     */
    public function runtimeFailed(Project $project, ?string $stage = null): void
    {
        $state = $this->runtimeSnapshots->has($project) ? 'stale' : 'failed';
        $error = match ($stage) {
            'bootstrap' => 'The application failed to boot.',
            'configuration' => 'The application configuration is invalid.',
            default => 'Runtime indexing failed.',
        };
        $this->section($project, 'runtime', $state, $error, $stage);
    }

    public function removeProject(Project $project): void
    {
        unset($this->statuses[$project->rootPath]);
        $this->runtimeSnapshots->removeProject($project);
    }

    /**
     * @return array{root: string, source: array{state: string, error?: string}, runtime: array{state: string, error?: string, stage?: string, lastSuccessfulAt?: string}}
     */
    public function status(Project $project): array
    {
        $status = $this->statuses[$project->rootPath] ?? [
            'source' => ['state' => 'not-indexed'],
            'runtime' => ['state' => 'not-indexed'],
        ];

        return ['root' => $project->rootPath, ...$status];
    }

    /**
     * @param 'source'|'runtime' $section
     */
    private function section(Project $project, string $section, string $state, ?string $error = null, ?string $stage = null): void
    {
        $status = $this->status($project);
        unset($status['root']);
        $status[$section] = ['state' => $state];
        if ('runtime' === $section && 'stale' === $state && null !== ($lastSuccessfulAt = $this->runtimeSnapshots->lastSuccessfulAt($project))) {
            $status[$section]['lastSuccessfulAt'] = $lastSuccessfulAt;
        }
        if (null !== $error && '' !== $error) {
            $status[$section]['error'] = $error;
        }
        if (null !== $stage) {
            $status[$section]['stage'] = $stage;
        }

        $this->statuses[$project->rootPath] = $status;
    }
}
