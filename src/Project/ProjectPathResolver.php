<?php

namespace Symfony\Lsp\Project;

/**
 * Answers the path questions of `ProjectPathPolicy` for document URIs.
 */
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

        return null === $path ? null : $this->paths->relative($project, $path);
    }

    public function isApplicationOwned(Project $project, string $uri): bool
    {
        $path = $this->uriToPathConverter->convert($uri);

        return null !== $path && $this->paths->owns($project, $path);
    }
}
