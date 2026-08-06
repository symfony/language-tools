<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;

final class TemplateNameResolver
{
    public function __construct(
        private readonly ProjectPathResolver $pathResolver,
    ) {
    }

    public function relative(Project $project, string $uri): ?string
    {
        $path = $this->pathResolver->relative($project, $uri);

        return null !== $path && str_starts_with($path, 'templates/') ? substr($path, \strlen('templates/')) : null;
    }

    public function resolve(Project $project, string $uri): ?string
    {
        $name = $this->relative($project, $uri);
        if (null === $name || !str_starts_with($name, 'bundles/')) {
            return $name;
        }

        $parts = explode('/', $name, 3);

        return 3 === \count($parts) ? '@'.$parts[1].'/'.$parts[2] : $name;
    }
}
