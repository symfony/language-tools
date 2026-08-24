<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Glob;

final class ProjectFileScopeRegistry implements ProjectStateInterface
{
    /** @var array<string, list<string>> */
    private array $patterns = [];

    /** @param list<string> $patterns */
    public function configure(Project $project, array $patterns): void
    {
        $this->patterns[$project->rootPath()] = array_map(
            static fn (string $pattern): string => Glob::toRegex($pattern, false, true, '~'),
            $patterns,
        );
    }

    public function isExcluded(Project $project, string $path): bool
    {
        $root = Path::canonicalize($project->rootPath());
        $path = Path::canonicalize($path);
        if ($root === $path || !Path::isBasePath($root, $path)) {
            return false;
        }

        $relativePath = str_replace('\\', '/', Path::makeRelative($path, $root));
        foreach ($this->patterns[$project->rootPath()] ?? [] as $pattern) {
            if (1 === preg_match($pattern, $relativePath)) {
                return true;
            }
        }

        return false;
    }

    public function isDirectoryExcluded(Project $project, string $path): bool
    {
        if ($this->isExcluded($project, $path)) {
            return true;
        }

        return $this->isExcluded($project, Path::join($path, '.symfony-lsp-scope'));
    }

    public function removeProject(Project $project): void
    {
        unset($this->patterns[$project->rootPath()]);
    }
}
