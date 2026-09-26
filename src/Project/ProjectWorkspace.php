<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;

/**
 * Discovers the Symfony projects of a workspace and keeps the registry,
 * the project state and the project settings in sync with them.
 */
final class ProjectWorkspace
{
    /** @var list<array{uri: string, name?: string}> */
    private array $folders = [];

    /** @var list<string> */
    private array $projectRoots = [];

    private ?string $configurationPath = null;

    public function __construct(
        private readonly ProjectConfiguration $projectConfiguration,
        private readonly ProjectDiscovery $projectDiscovery,
        private readonly ProjectRegistry $projects,
        private readonly ProjectSettings $projectSettings,
        private readonly ProjectStateCleaner $projectStateCleaner,
        private readonly AnalysisSettingsRegistry $analysisSettings,
        private readonly UriToPathConverter $uriToPathConverter,
    ) {
    }

    /**
     * @param list<array{uri: string, name?: string}> $folders
     * @param list<string>                            $projectRoots
     */
    public function configure(array $folders, ProjectAnalysisSettings $settings = new ProjectAnalysisSettings(), array $projectRoots = [], ?string $configurationPath = null): void
    {
        $this->folders = $folders;
        $this->projectRoots = $projectRoots;
        $this->configurationPath = $configurationPath;
        $this->loadConfiguration();
        $this->analysisSettings->configureWorkspace($settings);
    }

    /** @return list<array{uri: string, name?: string}> */
    public function folders(): array
    {
        return $this->folders;
    }

    /** @param list<array{uri: string, name?: string}> $folders */
    public function changeFolders(array $folders): void
    {
        $this->folders = $folders;
        $this->loadConfiguration();
    }

    public function loadConfiguration(): void
    {
        $this->projectConfiguration->load($this->folders, $this->configurationPath);
    }

    /**
     * @return list<Project>
     */
    public function discover(): array
    {
        $explicitRoots = $this->resolveRoots($this->projectRoots, $this->folders);
        $configuredRoots = [];
        foreach ($this->folders as $folder) {
            $path = $this->uriToPathConverter->convert($folder['uri']);
            $roots = null === $path ? null : $this->projectConfiguration->projectRoots($path);
            $configuredRoots[] = $this->resolveRoots($roots ?? [], [$folder]);
        }

        $projects = $this->discoverProjects($explicitRoots, $configuredRoots);
        $this->assertDiscovered(array_merge($explicitRoots, ...$configuredRoots), $projects);
        $this->projectConfiguration->validateProjects($projects);

        foreach ($this->projects->replace($projects) as $removed) {
            $this->projectStateCleaner->remove($removed);
        }
        $this->projectSettings->applyFileSettings();

        return $projects;
    }

    /**
     * @param list<array{root: string, paths: list<string>}>       $explicitRoots
     * @param list<list<array{root: string, paths: list<string>}>> $configuredRoots
     *
     * @return list<Project>
     */
    private function discoverProjects(array $explicitRoots, array $configuredRoots): array
    {
        if ([] !== $explicitRoots) {
            return $this->projectDiscovery->discover($this->folders, self::paths($explicitRoots));
        }

        $projects = [];
        foreach ($this->folders as $index => $folder) {
            array_push($projects, ...$this->projectDiscovery->discover([$folder], self::paths($configuredRoots[$index])));
        }

        $unique = [];
        foreach ($projects as $project) {
            $unique[$project->rootPath] = $project;
        }
        $projects = array_values($unique);
        usort($projects, static fn (Project $left, Project $right): int => strcmp($left->rootPath, $right->rootPath));

        return $projects;
    }

    /**
     * Every configured root is reported with the path the user wrote, which is
     * what they can act on.
     *
     * @param list<string>                            $roots
     * @param list<array{uri: string, name?: string}> $folders
     *
     * @return list<array{root: string, paths: list<string>}>
     */
    private function resolveRoots(array $roots, array $folders): array
    {
        $resolved = [];
        foreach ($roots as $root) {
            $paths = $this->rootPaths($root, $folders);
            if ([] === $paths) {
                throw new InvalidConfigurationException(\sprintf('The project root "%s" is outside the workspace.', $root));
            }
            foreach ($paths as $path) {
                if (!$this->isInWorkspace($path)) {
                    throw new InvalidConfigurationException(\sprintf('The project root "%s" is outside the workspace.', $root));
                }
            }
            $resolved[] = ['root' => $root, 'paths' => $paths];
        }

        return $resolved;
    }

    /**
     * @param list<array{uri: string, name?: string}> $folders
     *
     * @return list<string>
     */
    private function rootPaths(string $root, array $folders): array
    {
        if (str_starts_with($root, 'file:')) {
            $path = $this->uriToPathConverter->convert($root);

            return null === $path ? [] : [$path];
        }
        if (Path::isAbsolute($root)) {
            return [Path::canonicalize($root)];
        }

        $paths = [];
        foreach ($folders as $folder) {
            $folderPath = $this->uriToPathConverter->convert($folder['uri']);
            if (null !== $folderPath) {
                $paths[] = Path::canonicalize(Path::join($folderPath, $root));
            }
        }

        return array_values(array_unique($paths));
    }

    private function isInWorkspace(string $path): bool
    {
        foreach ($this->folders as $folder) {
            $folderPath = $this->uriToPathConverter->convert($folder['uri']);
            if (null !== $folderPath
                && PathContainment::contains($folderPath, $path)
                && PathContainment::resolvesInside($folderPath, $path)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{root: string, paths: list<string>}> $roots
     * @param list<Project>                                  $projects
     */
    private function assertDiscovered(array $roots, array $projects): void
    {
        $discovered = [];
        foreach ($projects as $project) {
            $discovered[Path::canonicalize($project->rootPath)] = true;
        }
        foreach ($roots as $root) {
            foreach ($root['paths'] as $path) {
                if (!isset($discovered[$path])) {
                    throw new InvalidConfigurationException(\sprintf('The project root "%s" was not discovered as a Symfony project.', $root['root']));
                }
            }
        }
    }

    /**
     * @param list<array{root: string, paths: list<string>}> $roots
     *
     * @return list<string>
     */
    private static function paths(array $roots): array
    {
        $paths = [];
        foreach ($roots as $root) {
            array_push($paths, ...$root['paths']);
        }

        return array_values(array_unique($paths));
    }
}
