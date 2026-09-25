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

        return PathContainment::resolvesInside($project->rootPath, $path, false);
    }
}
