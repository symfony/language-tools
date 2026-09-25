<?php

namespace Symfony\Lsp\Project;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class WorkspaceConfiguration
{
    public function __construct(
        private readonly ProjectWorkspace $workspace,
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
        $this->negotiatePositionEncoding($params);
        $this->projectSettings->initialize($params);

        $initializationOptions = $params['initializationOptions'] ?? null;
        $settings = \is_array($initializationOptions) ? $initializationOptions : [];
        $this->workspace->configure(
            $this->workspaceFolders($params),
            $settings,
            $this->projectRoots($settings),
            containedProjectRoots: false,
        );
        $this->workspace->discover();

        if (\is_array($initializationOptions)) {
            $this->workspaceTrustManager->applyInitializationOptions($params, $this->projectRegistry->all());
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

    public function reloadProjectConfiguration(): void
    {
        $this->workspace->loadConfiguration();
        $this->workspace->discover();
    }

    public function rediscoverProjects(): void
    {
        $this->workspace->discover();
    }

    /** @return list<string> */
    public function requestWorkspaceTrust(): array
    {
        $runtimeProjects = [];
        foreach ($this->projectRegistry->all() as $project) {
            if ($this->runtimeConfiguration->runtimeIndexing($project)) {
                $runtimeProjects[] = $project;
            } else {
                $this->workspaceTrustManager->invalidateRuntime($project);
            }
        }

        return $this->workspaceTrustManager->requestUnknownDecisions($runtimeProjects);
    }

    /** @param array<array-key, mixed> $event */
    public function changeWorkspaceFolders(array $event): void
    {
        $removed = [];
        foreach (\is_array($event['removed'] ?? null) ? $event['removed'] : [] as $folder) {
            if (\is_array($folder) && \is_string($folder['uri'] ?? null)) {
                $removed[rtrim($folder['uri'], '/')] = true;
            }
        }
        $folders = array_values(array_filter(
            $this->workspace->folders(),
            static fn (array $folder): bool => !isset($removed[rtrim($folder['uri'], '/')]),
        ));

        $known = [];
        foreach ($folders as $folder) {
            $known[rtrim($folder['uri'], '/')] = true;
        }
        foreach (\is_array($event['added'] ?? null) ? $event['added'] : [] as $folder) {
            if (!\is_array($folder) || !\is_string($folder['uri'] ?? null) || isset($known[rtrim($folder['uri'], '/')])) {
                continue;
            }
            $folders[] = \is_string($folder['name'] ?? null)
                ? ['uri' => $folder['uri'], 'name' => $folder['name']]
                : ['uri' => $folder['uri']];
        }

        $this->workspace->changeFolders($folders);
        $this->workspace->discover();
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

    /**
     * @param array<array-key, mixed> $initializationOptions
     *
     * @return list<string>
     */
    private function projectRoots(array $initializationOptions): array
    {
        $projectRoots = $initializationOptions['projectRoots'] ?? null;
        if (!\is_array($projectRoots) || !array_is_list($projectRoots)) {
            return [];
        }

        $roots = [];
        foreach ($projectRoots as $root) {
            if (!\is_string($root) || '' === $root) {
                return [];
            }
            $roots[] = $root;
        }

        return $roots;
    }
}
