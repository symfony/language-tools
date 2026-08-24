<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;
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
        private readonly ProjectConfiguration $projectConfiguration,
        private readonly PositionConverter $positionConverter,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly ProjectStateCleaner $projectStateCleaner,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function initialize(array $params): void
    {
        $this->negotiatePositionEncoding($params);
        $this->projectSettings->initialize($params);
        $this->workspaceFolders = $this->workspaceFolders($params);
        $this->projectConfiguration->load($this->workspaceFolders);

        $initializationOptions = $params['initializationOptions'] ?? null;
        if (\is_array($initializationOptions)) {
            $this->runtimeConfiguration->configure($initializationOptions);
        }

        $this->rediscoverProjects();
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
        $this->projectConfiguration->load($this->workspaceFolders);
        $this->rediscoverProjects();
    }

    public function requestWorkspaceTrust(): void
    {
        $runtimeProjects = [];
        foreach ($this->projectRegistry->all() as $project) {
            if ($this->runtimeConfiguration->runtimeIndexing($project)) {
                $runtimeProjects[] = $project;
            } else {
                $this->workspaceTrustManager->invalidateRuntime($project);
            }
        }
        $this->workspaceTrustManager->requestUnknownDecisions($runtimeProjects);
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

        $this->projectConfiguration->load($this->workspaceFolders);
        $this->rediscoverProjects();
    }

    public function rediscoverProjects(): void
    {
        $projects = [];
        $initializationRoots = $this->runtimeConfiguration->projectRoots();
        if ([] !== $initializationRoots) {
            $projects = $this->projectDiscovery->discover($this->workspaceFolders, $initializationRoots);
        } else {
            foreach ($this->workspaceFolders as $folder) {
                $path = $this->workspaceFolderPath($folder);
                $roots = null === $path ? null : $this->projectConfiguration->projectRoots($path);
                array_push($projects, ...$this->projectDiscovery->discover([$folder], $roots ?? []));
            }
        }

        $unique = [];
        foreach ($projects as $project) {
            $unique[$project->rootPath()] = $project;
        }
        $projects = array_values($unique);
        usort($projects, static fn (Project $left, Project $right): int => strcmp($left->rootPath(), $right->rootPath()));
        if ([] !== $initializationRoots) {
            $this->validateInitializationRoots($initializationRoots, $projects);
        }
        $this->projectConfiguration->validateProjects($projects);

        $change = $this->projectRegistry->replace($projects);
        foreach ($change->removed as $project) {
            $this->projectStateCleaner->remove($project);
        }
        $this->projectSettings->applyFileSettings();
    }

    /**
     * @param list<string>  $roots
     * @param list<Project> $projects
     */
    private function validateInitializationRoots(array $roots, array $projects): void
    {
        $discovered = [];
        foreach ($projects as $project) {
            $discovered[$project->rootPath()] = true;
        }
        foreach ($roots as $root) {
            $paths = [];
            if (str_starts_with($root, 'file:')) {
                $path = $this->uriToPathConverter->convert($root);
                $paths = null === $path ? [] : [$path];
            } elseif (Path::isAbsolute($root)) {
                $paths = [Path::canonicalize($root)];
            } else {
                foreach ($this->workspaceFolders as $folder) {
                    $workspace = $this->uriToPathConverter->convert($folder['uri']);
                    if (null !== $workspace) {
                        $paths[] = Path::join($workspace, $root);
                    }
                }
            }
            if ([] === $paths) {
                throw new InvalidConfigurationException(\sprintf('The configured project root "%s" is invalid.', $root));
            }
            foreach ($paths as $path) {
                if (!isset($discovered[$path])) {
                    throw new InvalidConfigurationException(\sprintf('The configured project root "%s" was not discovered as a Symfony project.', $root));
                }
            }
        }
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

    /** @param array{uri: string, name?: string} $folder */
    private function workspaceFolderPath(array $folder): ?string
    {
        return $this->uriToPathConverter->convert($folder['uri']);
    }
}
