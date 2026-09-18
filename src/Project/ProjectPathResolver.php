<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;

final class ProjectPathResolver
{
    public function __construct(
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly ProjectPathPolicy $paths,
    ) {
    }

    public function relative(Project $project, string $uri): ?string
    {
        $path = $this->uriToPathConverter->convert($uri);
        $root = Path::canonicalize($project->rootPath);
        if (null === $path || !Path::isBasePath($root, $path)) {
            return null;
        }

        return Path::makeRelative($path, $root);
    }

    public function isApplicationOwned(Project $project, string $uri): bool
    {
        $path = $this->uriToPathConverter->convert($uri);
        if (null === $path || null === $this->relative($project, $uri) || $this->paths->isExcluded($project, $path)) {
            return false;
        }

        $root = realpath($project->rootPath);
        if (false === $root) {
            return true;
        }
        if (null === $resolvedPath = $this->resolveExistingPath($path)) {
            return false;
        }

        $root = Path::canonicalize($root);

        return $root !== $resolvedPath && Path::isBasePath($root, $resolvedPath);
    }

    private function resolveExistingPath(string $path): ?string
    {
        if (file_exists($path) || is_link($path)) {
            $resolvedPath = realpath($path);

            return false === $resolvedPath ? null : Path::canonicalize($resolvedPath);
        }

        $parent = \dirname($path);
        while (!file_exists($parent) && !is_link($parent)) {
            $next = \dirname($parent);
            if ($next === $parent) {
                return null;
            }
            $parent = $next;
        }
        $resolvedParent = realpath($parent);

        return false === $resolvedParent ? null : Path::join($resolvedParent, Path::makeRelative($path, $parent));
    }
}
