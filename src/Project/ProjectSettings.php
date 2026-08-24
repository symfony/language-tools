<?php

namespace Symfony\Lsp\Project;

use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Feature\Translation\TranslationConfigurationRegistry;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class ProjectSettings
{
    private bool $configurationSupported = false;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly ProjectRegistry $projects,
        private readonly TranslationConfigurationRegistry $translationConfiguration,
        private readonly RuntimeConfiguration $runtimeConfiguration,
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

    /** @param array<array-key, mixed> $overrides */
    public function applyFileSettings(array $overrides = []): void
    {
        $overrides = $this->analysisSettings->normalizeProject($overrides, false);
        foreach ($this->projects->all() as $project) {
            $this->apply($project, $overrides);
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
                    'scopeUri' => $project->rootUri(),
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

    /** @param array<string, mixed> $overrides */
    private function apply(Project $project, array $overrides): void
    {
        $settings = [
            ...$this->projectConfiguration->settings($project),
            ...$this->runtimeConfiguration->initializationSettings(),
            ...$overrides,
        ];
        $this->translationConfiguration->configure($project, true === ($settings['translationDiagnostics'] ?? false));
        /** @var list<string> $excludePaths */
        $excludePaths = $settings['excludePaths'] ?? [];
        $this->fileScope->configure($project, $excludePaths);
        $this->runtimeConfiguration->configureProject($project, $settings);
    }
}
