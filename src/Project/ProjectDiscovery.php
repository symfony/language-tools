<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

final class ProjectDiscovery
{
    public function __construct(
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly GitignoreMatcher $gitignore,
    ) {
    }

    /**
     * @param list<array{uri: string, name?: string}> $workspaceFolders
     * @param list<string>                            $projectRoots
     *
     * @return list<Project>
     */
    public function discover(array $workspaceFolders, array $projectRoots = []): array
    {
        $roots = [];
        $explicitRoots = [];
        if ([] !== $projectRoots) {
            foreach ($projectRoots as $configuredRoot) {
                foreach ($this->configuredPaths($configuredRoot, $workspaceFolders) as $path) {
                    $roots[$path] = $this->uriToPathConverter->toUri($path);
                    $explicitRoots[$path] = true;
                }
            }
        } else {
            foreach ($workspaceFolders as $workspaceFolder) {
                $rootPath = $this->uriToPathConverter->convert($workspaceFolder['uri']);
                if (null === $rootPath) {
                    continue;
                }
                foreach ($this->candidateRoots($rootPath) as $candidate) {
                    $roots[$candidate] = $this->uri($workspaceFolder['uri'], $rootPath, $candidate);
                }
            }
        }

        $projects = [];
        foreach ($roots as $rootPath => $rootUri) {
            $project = $this->discoverRoot($rootPath, $rootUri, isset($explicitRoots[$rootPath]));
            if (null !== $project) {
                $projects[] = $project;
            }
        }

        usort($projects, static fn (Project $left, Project $right): int => strcmp($left->rootPath, $right->rootPath));

        return array_values(array_filter(
            $projects,
            static fn (Project $project): bool => !self::isInstalledDependency($project, $projects),
        ));
    }

    /** @param list<Project> $projects */
    private static function isInstalledDependency(Project $project, array $projects): bool
    {
        foreach ($projects as $candidate) {
            if ($candidate === $project || null === $candidate->vendorPath) {
                continue;
            }
            if (Path::isBasePath(Path::join($candidate->rootPath, $candidate->vendorPath), $project->rootPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{uri: string, name?: string}> $workspaceFolders
     *
     * @return list<string>
     */
    private function configuredPaths(string $configuredRoot, array $workspaceFolders): array
    {
        if (str_starts_with($configuredRoot, 'file:')) {
            $path = $this->uriToPathConverter->convert($configuredRoot);

            return null === $path ? [] : [$path];
        }
        if (Path::isAbsolute($configuredRoot)) {
            return [Path::canonicalize($configuredRoot)];
        }

        $paths = [];
        foreach ($workspaceFolders as $workspaceFolder) {
            $workspacePath = $this->uriToPathConverter->convert($workspaceFolder['uri']);
            if (null !== $workspacePath) {
                $paths[] = Path::join($workspacePath, $configuredRoot);
            }
        }

        return array_values(array_unique($paths));
    }

    /** @return \Generator<int, string> */
    private function candidateRoots(string $directory): \Generator
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = (new Finder())
            ->files()
            ->name('composer.json')
            ->in($directory)
            ->filter(fn (\SplFileInfo $file): bool => !$this->gitignore->isIgnored($directory, $file->getPathname()), true)
            ->exclude(ProjectPathPolicy::TOOL_DIRECTORIES)
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->ignoreUnreadableDirs();
        foreach ($files as $path => $file) {
            yield \dirname(Path::canonicalize($path));
        }
    }

    private function discoverRoot(string $rootPath, string $rootUri, bool $explicit): ?Project
    {
        $composerPath = Path::join($rootPath, 'composer.json');
        if (!is_file($composerPath)) {
            return null;
        }

        try {
            $composer = json_decode((string) file_get_contents($composerPath), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($composer)) {
            return null;
        }

        $require = \is_array($composer['require'] ?? null) ? $composer['require'] : [];
        $constraint = $require['symfony/framework-bundle'] ?? null;

        if (!\is_string($constraint)) {
            $constraint = $this->lockedFrameworkBundleVersion($rootPath);
        }

        if (!\is_string($constraint)) {
            return null;
        }

        if (!$explicit && 'project' !== ($composer['type'] ?? null) && !is_file(Path::join($rootPath, 'bin/console'))) {
            return null;
        }

        return new Project($rootPath, rtrim($rootUri, '/'), $this->vendorPath($rootPath, $composer));
    }

    /**
     * Composer installs dependencies in `vendor/` unless the project declares another directory.
     *
     * @param array<array-key, mixed> $composer
     */
    private function vendorPath(string $rootPath, array $composer): ?string
    {
        $config = \is_array($composer['config'] ?? null) ? $composer['config'] : [];
        $vendorDir = $config['vendor-dir'] ?? 'vendor';
        if (!\is_string($vendorDir) || '' === $vendorDir) {
            return 'vendor';
        }

        $root = Path::canonicalize($rootPath);
        $vendorPath = Path::canonicalize(Path::isAbsolute($vendorDir) ? $vendorDir : Path::join($root, $vendorDir));
        if ($root === $vendorPath || !Path::isBasePath($root, $vendorPath)) {
            return null;
        }

        return str_replace('\\', '/', Path::makeRelative($vendorPath, $root));
    }

    /**
     * Applications built on a distribution kernel, such as Contao or Shopware,
     * require the framework only transitively, so the lock file decides.
     */
    private function lockedFrameworkBundleVersion(string $rootPath): ?string
    {
        $lockPath = Path::join($rootPath, 'composer.lock');
        if (!is_file($lockPath)) {
            return null;
        }

        try {
            $lock = json_decode((string) file_get_contents($lockPath), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($lock)) {
            return null;
        }
        foreach (\is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $package) {
            if (!\is_array($package) || 'symfony/framework-bundle' !== ($package['name'] ?? null)) {
                continue;
            }
            $version = $package['version'] ?? null;
            if (\is_string($version)) {
                return ltrim($version, 'v');
            }
        }

        return null;
    }

    private function uri(string $workspaceUri, string $workspacePath, string $projectPath): string
    {
        if ($workspacePath === $projectPath) {
            return rtrim($workspaceUri, '/');
        }

        $relativePath = Path::makeRelative($projectPath, $workspacePath);
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));

        return rtrim($workspaceUri, '/').'/'.$encodedPath;
    }
}
