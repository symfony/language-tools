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
    ) {
    }

    /** @param array<array-key, mixed> $initializeParams */
    public function initialize(array $initializeParams): void
    {
        $capabilities = $initializeParams['capabilities'] ?? null;
        $workspace = \is_array($capabilities) ? ($capabilities['workspace'] ?? null) : null;
        $this->configurationSupported = \is_array($workspace) && true === ($workspace['configuration'] ?? null);
    }

    public function refresh(): void
    {
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
            if (!\is_array($settings)) {
                continue;
            }
            $this->translationConfiguration->configure($project, true === ($settings['translationDiagnostics'] ?? null));
            $this->runtimeConfiguration->configureProject($project, $settings);
        }
    }
}
