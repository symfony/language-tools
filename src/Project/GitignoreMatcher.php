<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Iterator\VcsIgnoredFilterIterator;

final class GitignoreMatcher
{
    public function isIgnored(string $rootPath, string $path): bool
    {
        return 0 === iterator_count($this->filter([$path], $rootPath));
    }

    /**
     * @param iterable<\SplFileInfo|string> $files
     *
     * @return \Generator<int, string>
     */
    public function filter(iterable $files, string $rootPath): \Generator
    {
        $lexicalFiles = (static function () use ($files): \Generator {
            foreach ($files as $file) {
                $path = Path::canonicalize((string) $file);
                yield $path => new LexicalPathFileInfo($path);
            }
        })();

        foreach (new VcsIgnoredFilterIterator($lexicalFiles, Path::canonicalize($rootPath)) as $file) {
            yield $file->getPathname();
        }
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
