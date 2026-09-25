<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Iterator\VcsIgnoredFilterIterator;

final class GitignoreMatcher implements ProjectStateInterface
{
    /** @var array<string, GitignoreRootMatcher> */
    private array $roots = [];

    public function isIgnored(string $rootPath, string $path): bool
    {
        return $this->root($rootPath)->isIgnored(Path::canonicalize($path));
    }

    /**
     * @param iterable<\SplFileInfo|string> $files
     *
     * @return \Generator<int, string>
     */
    public function filter(iterable $files, string $rootPath): \Generator
    {
        $root = $this->root($rootPath);
        foreach ($files as $file) {
            $path = Path::canonicalize((string) $file);
            if (!$root->isIgnored($path)) {
                yield $path;
            }
        }
    }

    public function removeProject(Project $project): void
    {
        unset($this->roots[Path::canonicalize($project->rootPath)]);
    }

    private function root(string $rootPath): GitignoreRootMatcher
    {
        $rootPath = Path::canonicalize($rootPath);

        return $this->roots[$rootPath] ??= new GitignoreRootMatcher($rootPath);
    }
}

/**
 * Keeps one upstream matcher per root, which compiles each .gitignore file it reads only once.
 */
final class GitignoreRootMatcher
{
    private readonly PendingPathIterator $pending;

    private readonly string $resolvedRootPath;

    private ?VcsIgnoredFilterIterator $matcher = null;

    /** @var array<string, string> */
    private array $stamps = [];

    public function __construct(private readonly string $rootPath)
    {
        $this->pending = new PendingPathIterator();
        $realRootPath = realpath($rootPath);
        $this->resolvedRootPath = false === $realRootPath ? $rootPath : Path::canonicalize($realRootPath);
    }

    public function isIgnored(string $path): bool
    {
        $path = $this->resolve($path);
        $this->discardOutdatedRules($path);
        $this->matcher ??= new VcsIgnoredFilterIterator($this->pending, $this->resolvedRootPath);
        $this->pending->set(new LexicalPathFileInfo($path));
        $this->matcher->rewind();

        return !$this->matcher->valid();
    }

    /** Finder resolves its base directory, so paths below a symlinked root must follow it. */
    private function resolve(string $path): string
    {
        if ($this->resolvedRootPath === $this->rootPath) {
            return $path;
        }
        if ($path === $this->rootPath) {
            return $this->resolvedRootPath;
        }
        if (str_starts_with($path, $this->rootPath.'/')) {
            return $this->resolvedRootPath.substr($path, \strlen($this->rootPath));
        }

        return $path;
    }

    /** Only the .gitignore files above a path decide its result, so its chain detects every rule change that applies. */
    private function discardOutdatedRules(string $path): void
    {
        $stamps = [];
        $outdated = false;
        $directory = \dirname($path);
        while (true) {
            $file = $directory.'/.gitignore';
            $stat = @stat($file);
            $stamp = false === $stat ? '' : $stat['mtime'].':'.$stat['size'];
            $stamps[$file] = $stamp;
            $outdated = $outdated || ($this->stamps[$file] ?? $stamp) !== $stamp;
            $parent = \dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        if ($outdated) {
            $this->stamps = [];
            $this->matcher = null;
        }

        $this->stamps += $stamps;
    }
}

/**
 * Feeds one path at a time to a filter iterator that outlives it.
 *
 * @implements \Iterator<string, \SplFileInfo>
 */
final class PendingPathIterator implements \Iterator
{
    private ?\SplFileInfo $file = null;

    public function set(\SplFileInfo $file): void
    {
        $this->file = $file;
    }

    public function current(): \SplFileInfo
    {
        \assert(null !== $this->file);

        return $this->file;
    }

    public function key(): string
    {
        return $this->current()->getPathname();
    }

    public function next(): void
    {
        $this->file = null;
    }

    public function rewind(): void
    {
    }

    public function valid(): bool
    {
        return null !== $this->file;
    }
}

/**
 * Keeps the lexical path: symlinked roots would defeat realpath matching and deleted files have none.
 */
final class LexicalPathFileInfo extends \SplFileInfo
{
    public function getRealPath(): string
    {
        return $this->getPathname();
    }
}
