<?php

namespace Symfony\Lsp\Project;

final class ProjectDiscovery
{
    public function __construct(
        private readonly UriToPathConverter $uriToPathConverter,
    ) {
    }

    /**
     * @param list<array{uri: string, name?: string}> $workspaceFolders
     *
     * @return list<Project>
     */
    public function discover(array $workspaceFolders): array
    {
        $projects = [];

        foreach ($workspaceFolders as $workspaceFolder) {
            $rootPath = $this->uriToPathConverter->convert($workspaceFolder['uri']);
            if (null === $rootPath) {
                continue;
            }

            $project = $this->discoverRoot($rootPath, $workspaceFolder['uri']);
            if (null !== $project) {
                $projects[] = $project;
            }
        }

        return $projects;
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

        return new Project($rootPath, $rootUri, $constraint);
    }
}
