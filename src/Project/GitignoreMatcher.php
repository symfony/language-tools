<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;

final class GitignoreMatcher
{
    public function isIgnored(string $rootPath, string $path): bool
    {
        return (new GitignoreEvaluation($rootPath))->isIgnored($path);
    }

    /**
     * @param iterable<\SplFileInfo|string> $files
     *
     * @return \Generator<int, string>
     */
    public function filter(iterable $files, string $rootPath): \Generator
    {
        $evaluation = new GitignoreEvaluation($rootPath);
        foreach ($files as $file) {
            $path = Path::canonicalize((string) $file);
            if (!$evaluation->isIgnored($path)) {
                yield $path;
            }
        }
    }
}
