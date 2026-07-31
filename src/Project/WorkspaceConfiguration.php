<?php

namespace Symfony\Lsp\Project;

use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class WorkspaceConfiguration
{
    public function __construct(
        private readonly ProjectDiscovery $projectDiscovery,
        private readonly ProjectRegistry $projectRegistry,
        private readonly WorkspaceTrustManager $workspaceTrustManager,
        private readonly RuntimeConfiguration $runtimeConfiguration,
        private readonly ProjectSettings $projectSettings,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function initialize(array $params): void
    {
        $initializationOptions = $params['initializationOptions'] ?? null;
        if (\is_array($initializationOptions)) {
            $this->runtimeConfiguration->configure($initializationOptions);
        }

        $this->projectSettings->initialize($params);
        $workspaceFolders = $this->workspaceFolders($params);
        $this->projectRegistry->replace($this->projectDiscovery->discover($workspaceFolders));
        $this->workspaceTrustManager->applyInitializationOptions($params, $this->projectRegistry);
    }

    public function refreshProjectSettings(): void
    {
        $this->projectSettings->refresh();
    }

    public function requestWorkspaceTrust(): void
    {
        $this->workspaceTrustManager->requestUnknownDecisions($this->projectRegistry);
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array{uri: string, name?: string}>
     */
    private function workspaceFolders(array $params): array
    {
        $folders = [];
        $workspaceFolders = $params['workspaceFolders'] ?? [];
        foreach (\is_array($workspaceFolders) ? $workspaceFolders : [] as $folder) {
            if (!\is_array($folder) || !\is_string($folder['uri'] ?? null)) {
                continue;
            }

            if (\is_string($folder['name'] ?? null)) {
                $folders[] = ['uri' => $folder['uri'], 'name' => $folder['name']];
            } else {
                $folders[] = ['uri' => $folder['uri']];
            }
        }

        if ([] === $folders && \is_string($params['rootUri'] ?? null)) {
            $folders[] = ['uri' => $params['rootUri']];
        }

        return $folders;
    }
}
