<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\AnalysisSettingsRegistry;
use Symfony\Lsp\Project\Project;

final class RuntimeConfiguration
{
    /** @var non-empty-list<string> */
    private readonly array $defaultPhpCommand;

    /** @param array<array-key, mixed> $defaultPhpCommand */
    public function __construct(
        private readonly AnalysisSettingsRegistry $settings = new AnalysisSettingsRegistry(),
        AnalysisSettings $analysisSettings = new AnalysisSettings(),
        array $defaultPhpCommand = ['php'],
    ) {
        $this->defaultPhpCommand = $analysisSettings->phpCommand($defaultPhpCommand, 'default runtime');
    }

    /** @return non-empty-list<string> */
    public function phpCommand(?Project $project = null): array
    {
        return $this->settings->forProject($project)->phpCommand ?? $this->defaultPhpCommand;
    }

    public function containerProjectRoot(?Project $project = null): ?string
    {
        $root = $this->settings->forProject($project)->containerProjectRoot;

        return '' === $root ? null : $root;
    }

    public function environment(?Project $project = null): string
    {
        return $this->settings->forProject($project)->environment ?? 'dev';
    }

    public function kernel(?Project $project = null): ?string
    {
        return $this->settings->forProject($project)->kernel;
    }

    public function debug(?Project $project = null): bool
    {
        return $this->settings->forProject($project)->debug ?? true;
    }

    public function runtimeIndexingRequested(?Project $project = null): bool
    {
        return $this->settings->forProject($project)->runtimeIndexing ?? true;
    }

    public function runtimeIndexing(?Project $project = null): bool
    {
        return $this->debug($project) && $this->runtimeIndexingRequested($project);
    }

    public function releaseMetadata(?Project $project = null): bool
    {
        return $this->settings->forProject($project)->releaseMetadata ?? true;
    }

    public function sourceOnlyReason(Project $project): ?string
    {
        if (!$this->debug($project)) {
            return 'debug-disabled';
        }
        if (!$this->runtimeIndexingRequested($project)) {
            return 'runtime-indexing-disabled';
        }

        return null;
    }

    public function bridgeTimeout(?Project $project = null): float
    {
        return $this->settings->forProject($project)->bridgeTimeout ?? 300.0;
    }
}
