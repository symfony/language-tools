<?php

namespace Symfony\Lsp\Project;

use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Feature\Translation\TranslationConfigurationRegistry;

final class ProjectSettings
{
    private bool $configurationSupported = false;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly ProjectRegistry $projects,
        private readonly TranslationConfigurationRegistry $translationConfiguration,
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
                    'section' => 'symfonyLsp.translationDiagnostics',
                ], $projects),
            ]);
        } catch (\Throwable) {
            return;
        }
        if (!\is_array($response)) {
            return;
        }

        foreach ($projects as $index => $project) {
            $value = $response[$index] ?? null;
            $this->translationConfiguration->configure($project, true === $value);
        }
    }
}
