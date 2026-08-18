<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Project\Project;

/**
 * Translates project paths between the host and the container project root
 * used by a PHP command that runs in Docker or another isolated environment.
 */
final class ContainerPathMapper
{
    public function __construct(private readonly RuntimeConfiguration $configuration)
    {
    }

    public function toContainer(Project $project, string $path): string
    {
        $containerRoot = $this->configuration->containerProjectRoot($project);
        if (null === $containerRoot || !Path::isBasePath($project->rootPath(), $path)) {
            return $path;
        }

        return Path::join($containerRoot, Path::makeRelative($path, $project->rootPath()));
    }

    public function toHost(Project $project, string $path): string
    {
        $containerRoot = $this->configuration->containerProjectRoot($project);
        if (null === $containerRoot || !Path::isAbsolute($path) || !Path::isBasePath($containerRoot, $path)) {
            return $path;
        }

        return Path::join($project->rootPath(), Path::makeRelative($path, $containerRoot));
    }
}
