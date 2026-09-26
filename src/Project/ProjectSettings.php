<?php

namespace Symfony\Lsp\Project;

use Symfony\Lsp\Client\ClientInterface;

final class ProjectSettings
{
    private bool $configurationSupported = false;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly ProjectRegistry $projects,
        private readonly AnalysisSettingsRegistry $settings,
        private readonly ProjectConfiguration $projectConfiguration,
        private readonly ProjectFileScopeRegistry $fileScope,
        private readonly AnalysisSettings $analysisSettings,
    ) {
    }

    /** @param array<array-key, mixed> $initializeParams */
    public function initialize(array $initializeParams): void
    {
        $capabilities = $initializeParams['capabilities'] ?? null;
        $workspace = \is_array($capabilities) ? ($capabilities['workspace'] ?? null) : null;
        $this->configurationSupported = \is_array($workspace) && true === ($workspace['configuration'] ?? null);
    }

    public function applyFileSettings(): void
    {
        foreach ($this->projects->all() as $project) {
            $this->apply($project, new ProjectAnalysisSettings());
        }
    }

    public function refresh(): void
    {
        $this->applyFileSettings();
        if (!$this->configurationSupported) {
            return;
        }

        $projects = $this->projects->all();
        try {
            $response = $this->client->request('workspace/configuration', [
                'items' => array_map(static fn (Project $project): array => [
                    'scopeUri' => $project->rootUri,
                    'section' => 'symfonyLsp',
                ], $projects),
            ]);
        } catch (\Throwable) {
            return;
        }
        if (!\is_array($response)) {
            return;
        }

        foreach ($projects as $index => $project) {
            $settings = $response[$index] ?? null;
            if (\is_array($settings)) {
                $this->apply($project, $this->analysisSettings->normalizeProject($settings, false));
            }
        }
    }

    /** The editor settings of a project win over the workspace, which wins over its checked-in configuration. */
    private function apply(Project $project, ProjectAnalysisSettings $editorSettings): void
    {
        $settings = $this->projectConfiguration->settings($project)
            ->merge($this->settings->workspace())
            ->merge($editorSettings);
        $this->fileScope->configure($project, $settings->excludePaths ?? []);
        $this->settings->configureProject($project, $settings);
    }
}
