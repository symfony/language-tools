<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;

final class ProjectPathResolver
{
    public function __construct(
        private readonly UriToPathConverter $uriToPathConverter,
    ) {
    }

    public function relative(Project $project, string $uri): ?string
    {
        $path = $this->uriToPathConverter->convert($uri);
        $root = Path::canonicalize($project->rootPath());
        if (null === $path || !Path::isBasePath($root, $path)) {
            return null;
        }

        return Path::makeRelative($path, $root);
    }
}
