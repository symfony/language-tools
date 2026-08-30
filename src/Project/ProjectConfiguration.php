<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;

final class ProjectConfiguration
{
    public const FILE_NAME = '.symfony-lsp.json';

    /** @var list<array{root: string, projectRoots: list<string>|null, settings: array<string, mixed>, projects: array<string, array<string, mixed>>}> */
    private array $workspaces = [];

    public function __construct(
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly AnalysisSettings $analysisSettings,
    ) {
    }

    /**
     * @param list<array{uri: string, name?: string}> $workspaceFolders
     */
    public function load(array $workspaceFolders, ?string $configurationPath = null): void
    {
        $this->workspaces = [];
        foreach ($workspaceFolders as $index => $folder) {
            $root = $this->uriToPathConverter->convert($folder['uri']);
            if (null === $root) {
                continue;
            }
            $root = Path::canonicalize($root);
            $explicit = null !== $configurationPath && 0 === $index;
            $path = $explicit
                ? $this->absolutePath($configurationPath, $root)
                : Path::join($root, self::FILE_NAME);
            if ($explicit && !is_file($path)) {
                throw new InvalidConfigurationException(\sprintf('The Symfony Language Tools configuration file "%s" does not exist.', $path));
            }
            $this->workspaces[] = $this->loadWorkspace($root, $path);
        }
    }

    /** @return list<string>|null */
    public function projectRoots(string $workspaceRoot): ?array
    {
        $workspaceRoot = Path::canonicalize($workspaceRoot);
        foreach ($this->workspaces as $workspace) {
            if ($workspace['root'] === $workspaceRoot) {
                return $workspace['projectRoots'];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function settings(Project $project): array
    {
        $workspace = $this->workspaceForPath($project->rootPath);
        if (null === $workspace) {
            return [];
        }

        return [
            ...$workspace['settings'],
            ...($workspace['projects'][Path::canonicalize($project->rootPath)] ?? []),
        ];
    }

    /** @param list<Project> $projects */
    public function validateProjects(array $projects): void
    {
        $discovered = [];
        foreach ($projects as $project) {
            $discovered[Path::canonicalize($project->rootPath)] = true;
        }
        foreach ($this->workspaces as $workspace) {
            foreach ($workspace['projectRoots'] ?? [] as $projectRoot) {
                if (!isset($discovered[$projectRoot])) {
                    throw new InvalidConfigurationException(\sprintf('The configured project root "%s" was not discovered as a Symfony project.', $workspace['root'] === $projectRoot ? '.' : Path::makeRelative($projectRoot, $workspace['root'])));
                }
            }
            foreach (array_keys($workspace['projects']) as $projectRoot) {
                if (!isset($discovered[$projectRoot])) {
                    throw new InvalidConfigurationException(\sprintf('The configured project "%s" was not discovered as a Symfony project.', $workspace['root'] === $projectRoot ? '.' : Path::makeRelative($projectRoot, $workspace['root'])));
                }
            }
        }
    }

    public function projectId(Project $project): string
    {
        $workspace = $this->workspaceForPath($project->rootPath);
        if (null === $workspace || $workspace['root'] === Path::canonicalize($project->rootPath)) {
            return '.';
        }

        return Path::makeRelative($project->rootPath, $workspace['root']);
    }

    public function workspaceRelativePath(Project $project, string $path): string
    {
        $workspace = $this->workspaceForPath($project->rootPath);

        return null === $workspace ? $path : Path::makeRelative($path, $workspace['root']);
    }

    /**
     * @return array{root: string, projectRoots: list<string>|null, settings: array<string, mixed>, projects: array<string, array<string, mixed>>}|null
     */
    private function workspaceForPath(string $path): ?array
    {
        $path = Path::canonicalize($path);
        $matches = array_values(array_filter(
            $this->workspaces,
            static fn (array $workspace): bool => $workspace['root'] === $path || Path::isBasePath($workspace['root'], $path),
        ));
        usort($matches, static fn (array $left, array $right): int => \strlen($right['root']) <=> \strlen($left['root']));

        return $matches[0] ?? null;
    }

    /**
     * @return array{root: string, projectRoots: list<string>|null, settings: array<string, mixed>, projects: array<string, array<string, mixed>>}
     */
    private function loadWorkspace(string $root, string $path): array
    {
        if (!is_file($path)) {
            return ['root' => $root, 'projectRoots' => null, 'settings' => [], 'projects' => []];
        }
        if (!is_readable($path)) {
            throw new InvalidConfigurationException(\sprintf('The Symfony Language Tools configuration file "%s" is unreadable.', $path));
        }

        try {
            $contents = file_get_contents($path);
            if (false === $contents) {
                throw new \RuntimeException();
            }
            $configuration = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new InvalidConfigurationException(\sprintf('The Symfony Language Tools configuration file "%s" is not valid JSON.', $path));
        }
        if (!\is_array($configuration) || 1 !== ($configuration['version'] ?? null)) {
            throw new InvalidConfigurationException(\sprintf('The Symfony Language Tools configuration file "%s" must use version 1.', $path));
        }

        $allowed = ['version', 'projectRoots', 'projects', ...AnalysisSettings::PROJECT_KEYS];
        foreach (array_keys($configuration) as $name) {
            if (!\is_string($name) || !\in_array($name, $allowed, true)) {
                throw new InvalidConfigurationException(\sprintf('Unknown configuration option "%s" in "%s".', \is_string($name) ? $name : (string) $name, $path));
            }
        }

        $projectRoots = null;
        if (\array_key_exists('projectRoots', $configuration)) {
            $projectRoots = $this->projectRootsValue($configuration['projectRoots'], $root, $path);
        }

        $settings = $this->analysisSettings->normalizeProject(
            array_intersect_key($configuration, array_flip(AnalysisSettings::PROJECT_KEYS)),
            context: \sprintf('configuration file "%s"', $path),
        );
        $projects = [];
        $configuredProjects = $configuration['projects'] ?? [];
        if (!\is_array($configuredProjects)) {
            throw new InvalidConfigurationException(\sprintf('The configuration option "projects" in "%s" must be an object.', $path));
        }
        foreach ($configuredProjects as $configuredRoot => $projectSettings) {
            if (!\is_string($configuredRoot) || '' === $configuredRoot || !\is_array($projectSettings)) {
                throw new InvalidConfigurationException(\sprintf('Every project entry in "%s" must have a non-empty path and an object value.', $path));
            }
            $projectRoot = $this->absolutePath($configuredRoot, $root);
            if (!$this->isInsideWorkspace($root, $projectRoot)) {
                throw new InvalidConfigurationException(\sprintf('The project entry "%s" in "%s" is outside the workspace.', $configuredRoot, $path));
            }
            $projects[$projectRoot] = $this->analysisSettings->normalizeProject(
                $projectSettings,
                context: \sprintf('project "%s" in "%s"', $configuredRoot, $path),
            );
        }

        return compact('root', 'projectRoots', 'settings', 'projects');
    }

    /** @return list<string> */
    private function projectRootsValue(mixed $value, string $root, string $path): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new InvalidConfigurationException(\sprintf('The configuration option "projectRoots" in "%s" must be a list of paths.', $path));
        }

        $roots = [];
        foreach ($value as $configuredRoot) {
            if (!\is_string($configuredRoot) || '' === $configuredRoot) {
                throw new InvalidConfigurationException(\sprintf('The configuration option "projectRoots" in "%s" must contain non-empty paths.', $path));
            }
            $projectRoot = $this->absolutePath($configuredRoot, $root);
            if (!$this->isInsideWorkspace($root, $projectRoot)) {
                throw new InvalidConfigurationException(\sprintf('The project root "%s" in "%s" is outside the workspace.', $configuredRoot, $path));
            }
            $roots[] = $projectRoot;
        }

        return array_values(array_unique($roots));
    }

    private function absolutePath(string $path, string $root): string
    {
        return Path::canonicalize(Path::isAbsolute($path) ? $path : Path::join($root, $path));
    }

    private function isInsideWorkspace(string $workspace, string $path): bool
    {
        if ($workspace !== $path && !Path::isBasePath($workspace, $path)) {
            return false;
        }
        $realWorkspace = realpath($workspace);
        $realPath = realpath($path);
        if (false === $realWorkspace || false === $realPath) {
            return true;
        }
        $realWorkspace = Path::canonicalize($realWorkspace);
        $realPath = Path::canonicalize($realPath);

        return $realWorkspace === $realPath || Path::isBasePath($realWorkspace, $realPath);
    }
}
