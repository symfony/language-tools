<?php

namespace Symfony\Lsp\Project;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class WorkspaceConfiguration
{
    /** @var list<array{uri: string, name?: string}> */
    private array $workspaceFolders = [];

    public function __construct(
        private readonly ProjectDiscovery $projectDiscovery,
        private readonly ProjectRegistry $projectRegistry,
        private readonly WorkspaceTrustManager $workspaceTrustManager,
        private readonly RuntimeConfiguration $runtimeConfiguration,
        private readonly ProjectSettings $projectSettings,
        private readonly PositionConverter $positionConverter,
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

        $this->negotiatePositionEncoding($params);
        $this->projectSettings->initialize($params);
        $this->workspaceFolders = $this->workspaceFolders($params);
        $this->rediscoverProjects();
        if ($this->runtimeConfiguration->runtimeIndexing()) {
            $this->workspaceTrustManager->applyInitializationOptions($params, $this->projectRegistry);
        }
    }

    public function positionEncoding(): string
    {
        return $this->positionConverter->encoding();
    }

    public function refreshProjectSettings(): void
    {
        $this->projectSettings->refresh();
    }

    public function requestWorkspaceTrust(): void
    {
        if (!$this->runtimeConfiguration->runtimeIndexing()) {
            return;
        }

        $projects = new ProjectRegistry();
        $projects->replace(array_values(array_filter(
            $this->projectRegistry->all(),
            fn (Project $project): bool => $this->runtimeConfiguration->runtimeIndexing($project),
        )));
        $this->workspaceTrustManager->requestUnknownDecisions($projects);
    }

    /** @param array<array-key, mixed> $params */
    public function changeWorkspaceFolders(array $params): void
    {
        $event = $params['event'] ?? null;
        if (!\is_array($event)) {
            return;
        }

        $removed = [];
        foreach (\is_array($event['removed'] ?? null) ? $event['removed'] : [] as $folder) {
            if (\is_array($folder) && \is_string($folder['uri'] ?? null)) {
                $removed[rtrim($folder['uri'], '/')] = true;
            }
        }
        $this->workspaceFolders = array_values(array_filter(
            $this->workspaceFolders,
            static fn (array $folder): bool => !isset($removed[rtrim($folder['uri'], '/')]),
        ));

        $known = [];
        foreach ($this->workspaceFolders as $folder) {
            $known[rtrim($folder['uri'], '/')] = true;
        }
        foreach (\is_array($event['added'] ?? null) ? $event['added'] : [] as $folder) {
            if (!\is_array($folder) || !\is_string($folder['uri'] ?? null) || isset($known[rtrim($folder['uri'], '/')])) {
                continue;
            }
            $this->workspaceFolders[] = \is_string($folder['name'] ?? null)
                ? ['uri' => $folder['uri'], 'name' => $folder['name']]
                : ['uri' => $folder['uri']];
        }

        $this->rediscoverProjects();
    }

    public function rediscoverProjects(): void
    {
        $this->projectRegistry->replace($this->projectDiscovery->discover(
            $this->workspaceFolders,
            $this->runtimeConfiguration->projectRoots(),
        ));
    }

    /** @param array<array-key, mixed> $params */
    private function negotiatePositionEncoding(array $params): void
    {
        $capabilities = $params['capabilities'] ?? null;
        $general = \is_array($capabilities) ? ($capabilities['general'] ?? null) : null;
        $encodings = \is_array($general) ? ($general['positionEncodings'] ?? null) : null;
        $this->positionConverter->negotiate(\is_array($encodings) ? array_values($encodings) : []);
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
