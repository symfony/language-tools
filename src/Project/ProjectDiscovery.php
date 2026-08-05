<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

final class ProjectDiscovery
{
    private const EXCLUDED_DIRECTORIES = [
        '.git',
        'node_modules',
        'var',
        'vendor',
    ];

    public function __construct(
        private readonly UriToPathConverter $uriToPathConverter,
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
        if ([] !== $projectRoots) {
            foreach ($projectRoots as $configuredRoot) {
                foreach ($this->configuredPaths($configuredRoot, $workspaceFolders) as $path) {
                    $roots[$path] = $this->uriToPathConverter->toUri($path);
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
            $project = $this->discoverRoot($rootPath, $rootUri);
            if (null !== $project) {
                $projects[] = $project;
            }
        }

        usort($projects, static fn (Project $left, Project $right): int => strcmp($left->rootPath(), $right->rootPath()));

        return $projects;
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
            return [rtrim(str_replace('\\', '/', $configuredRoot), '/')];
        }

        $paths = [];
        foreach ($workspaceFolders as $workspaceFolder) {
            $workspacePath = $this->uriToPathConverter->convert($workspaceFolder['uri']);
            if (null !== $workspacePath) {
                $paths[] = $workspacePath.'/'.trim(str_replace('\\', '/', $configuredRoot), '/');
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
            ->exclude(self::EXCLUDED_DIRECTORIES)
            ->ignoreDotFiles(false);
        foreach ($files as $file) {
            yield str_replace('\\', '/', $file->getPath());
        }
    }

    private function discoverRoot(string $rootPath, string $rootUri): ?Project
    {
        $composerPath = $rootPath.'/composer.json';
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
        $requireDev = \is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [];
        $constraint = $require['symfony/framework-bundle']
            ?? $requireDev['symfony/framework-bundle']
            ?? null;

        if (!\is_string($constraint)) {
            return null;
        }

        return new Project($rootPath, rtrim($rootUri, '/'), $constraint);
    }

    private function uri(string $workspaceUri, string $workspacePath, string $projectPath): string
    {
        if ($workspacePath === $projectPath) {
            return rtrim($workspaceUri, '/');
        }

        $relativePath = substr(str_replace('\\', '/', $projectPath), \strlen(rtrim(str_replace('\\', '/', $workspacePath), '/')) + 1);
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));

        return rtrim($workspaceUri, '/').'/'.$encodedPath;
    }
}
