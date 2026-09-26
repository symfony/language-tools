<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;

/**
 * Decides which project paths hold application sources.
 *
 * `owns()` is the single answer to "does this project own this path": the path
 * lies inside the project root, it still resolves inside it once symbolic links
 * are followed, and no tool or ignore rule claims it. Nothing is excluded by
 * convention: dependency locations come from the tools that own them and
 * everything else generated comes from the project's own ignore rules. What a
 * user excludes from analysis through `excludePaths` is a separate scope, kept
 * by `ProjectFileScopeRegistry`, and which environment loads a document is a
 * separate question, answered by `EnvironmentScopeResolver`.
 */
final class ProjectPathPolicy
{
    /** Git never tracks its own directory and Node resolves dependencies through `node_modules` at any depth. */
    public const TOOL_DIRECTORIES = ['.git', 'node_modules'];

    /** Where Symfony Language Tools keeps its own caches inside a project. */
    public const STORAGE_PATH = 'var/symfony-lsp';

    public function __construct(private readonly GitignoreMatcher $gitignore)
    {
    }

    public function owns(Project $project, string $path): bool
    {
        return null !== $this->relative($project, $path)
            && !$this->isExcluded($project, $path)
            && PathContainment::resolvesInside($project->rootPath, $path, false);
    }

    public function isExcluded(Project $project, string $path): bool
    {
        return $this->isToolOwned($project, $path) || $this->isIgnored($project, $path);
    }

    /** Paths owned by Git, Node, Symfony Language Tools or the Composer installation this project declares. */
    public function isToolOwned(Project $project, string $path): bool
    {
        $relativePath = $this->relative($project, $path);
        if (null === $relativePath) {
            return false;
        }

        if ($this->isWithin(self::STORAGE_PATH, $relativePath)) {
            return true;
        }

        if (null !== $project->vendorPath && $this->isWithin($project->vendorPath, $relativePath)) {
            return true;
        }

        foreach (explode('/', $relativePath) as $segment) {
            if (\in_array($segment, self::TOOL_DIRECTORIES, true)) {
                return true;
            }
        }

        return false;
    }

    /** Project-root dotenv files stay visible because Symfony reads them even when they are ignored. */
    public function isIgnored(Project $project, string $path): bool
    {
        if (null === $this->relative($project, $path)) {
            return false;
        }

        $path = Path::canonicalize($path);
        if (str_starts_with(basename($path), '.env') && Path::canonicalize($project->rootPath) === \dirname($path)) {
            return false;
        }

        return $this->gitignore->isIgnored($project->rootPath, $path);
    }

    /** The path relative to the project root, or null when it lies outside the project or is the root itself. */
    public function relative(Project $project, string $path): ?string
    {
        $root = Path::canonicalize($project->rootPath);
        $path = Path::canonicalize($path);
        if ($root === $path || !Path::isBasePath($root, $path)) {
            return null;
        }

        return str_replace('\\', '/', Path::makeRelative($path, $root));
    }

    private function isWithin(string $directory, string $relativePath): bool
    {
        return $relativePath === $directory || str_starts_with($relativePath, $directory.'/');
    }
}
