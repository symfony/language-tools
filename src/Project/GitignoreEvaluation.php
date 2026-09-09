<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;

/**
 * Evaluates ignore rules the way git does: level by level from the repository root,
 * last matching pattern wins, and nothing below an excluded directory can be
 * re-included by a negated pattern.
 */
final class GitignoreEvaluation
{
    private readonly string $baseDirectory;

    /** @var array<string, list<GitignorePattern>> */
    private array $patterns = [];

    /** @var array<string, bool> */
    private array $ignoredDirectories = [];

    public function __construct(string $rootPath)
    {
        $this->baseDirectory = self::repositoryRoot(Path::canonicalize($rootPath));
    }

    public function isIgnored(string $path): bool
    {
        $path = Path::canonicalize($path);
        if ($path === $this->baseDirectory || !Path::isBasePath($this->baseDirectory, $path)) {
            return false;
        }

        return $this->isPathIgnored($path, is_dir($path));
    }

    private function isPathIgnored(string $path, bool $isDirectory): bool
    {
        $parent = \dirname($path);
        if ($parent !== $this->baseDirectory && $this->isDirectoryIgnored($parent)) {
            return true;
        }

        $ignored = false;
        foreach ($this->directoriesDownwards($parent) as $directory) {
            $relativePath = substr($path, \strlen($directory) + 1);
            foreach ($this->patternsIn($directory) as $pattern) {
                if ($pattern->matches($relativePath, $isDirectory)) {
                    $ignored = !$pattern->negated;
                }
            }
        }

        return $ignored;
    }

    private function isDirectoryIgnored(string $directory): bool
    {
        return $this->ignoredDirectories[$directory] ??= $this->isPathIgnored($directory, true);
    }

    /** @return list<string> */
    private function directoriesDownwards(string $directory): array
    {
        $directories = [];
        while ($directory !== $this->baseDirectory) {
            $directories[] = $directory;
            $parent = \dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }
        $directories[] = $this->baseDirectory;

        return array_reverse($directories);
    }

    /** @return list<GitignorePattern> */
    private function patternsIn(string $directory): array
    {
        if (isset($this->patterns[$directory])) {
            return $this->patterns[$directory];
        }

        $patterns = [];
        $path = $directory.'/.gitignore';
        if (is_file($path) && false !== $contents = @file_get_contents($path)) {
            foreach (preg_split('~\r\n|\r|\n~', $contents) ?: [] as $line) {
                if (null !== $pattern = GitignorePattern::compile($line)) {
                    $patterns[] = $pattern;
                }
            }
        }

        return $this->patterns[$directory] = $patterns;
    }

    /**
     * Linked worktrees and submodules point at the common directory with a `.git` file.
     */
    private static function repositoryRoot(string $rootPath): string
    {
        $directory = $rootPath;
        while (true) {
            if (file_exists($directory.'/.git')) {
                return $directory;
            }
            $parent = \dirname($directory);
            if ($parent === $directory) {
                return $rootPath;
            }
            $directory = $parent;
        }
    }
}
