<?php

namespace Symfony\Lsp\Index;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Project\GitignoreMatcher;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectFileScopeRegistry;
use Symfony\Lsp\Project\ProjectPathPolicy;

final class SourceFileEnumerator
{
    private const LANGUAGE_IDS = [
        'ini' => 'ini',
        'js' => 'javascript',
        'json' => 'json',
        'mjs' => 'javascript',
        'php' => 'php',
        'ts' => 'typescript',
        'twig' => 'twig',
        'xlf' => 'xml',
        'xliff' => 'xml',
        'xml' => 'xml',
        'yaml' => 'yaml',
        'yml' => 'yaml',
    ];

    private const LOCK_FILES = [
        'npm-shrinkwrap.json',
        'package-lock.json',
        'pnpm-lock.yaml',
    ];

    public function __construct(
        private readonly GitignoreMatcher $gitignore,
        private readonly ProjectFileScopeRegistry $fileScope,
    ) {
    }

    /** @return \Generator<int, string> */
    public function files(Project $project, bool $includeExcluded = false): \Generator
    {
        foreach ($this->entries($project, $includeExcluded) as $entry) {
            if (isset($entry['path'])) {
                yield $entry['path'];
            }
        }
    }

    /** @return \Generator<int, array{path: string}|array{directory: string, error: 'outside'|'unreadable'}> */
    public function entries(Project $project, bool $includeExcluded = false): \Generator
    {
        $root = Path::canonicalize($project->rootPath);
        if (!is_dir($root)) {
            return;
        }

        $issues = [];
        $rootDotenvPaths = [];
        $rawFiles = (function () use ($project, $includeExcluded, $root, &$issues, &$rootDotenvPaths): \Generator {
            foreach ($this->traverse($project, $includeExcluded, $root) as $entry) {
                if (isset($entry['directory'])) {
                    $issues[] = $entry;

                    continue;
                }
                if ('dotenv' === $this->languageId($entry['path']) && $root === \dirname($entry['path'])) {
                    $rootDotenvPaths[$entry['path']] = true;
                }
                yield $entry['path'];
            }
        })();

        $yielded = [];
        $issueOffset = 0;
        foreach ($this->gitignore->filter($rawFiles, $root) as $path) {
            while (isset($issues[$issueOffset])) {
                $issue = $issues[$issueOffset++];
                if (!$this->gitignore->isIgnored($root, $issue['directory'])) {
                    yield $issue;
                }
            }
            $path = Path::canonicalize($path);
            $yielded[$path] = true;
            yield ['path' => $path];
        }
        while (isset($issues[$issueOffset])) {
            $issue = $issues[$issueOffset++];
            if (!$this->gitignore->isIgnored($root, $issue['directory'])) {
                yield $issue;
            }
        }
        foreach (array_keys($rootDotenvPaths) as $path) {
            if (!isset($yielded[$path])) {
                yield ['path' => $path];
            }
        }
    }

    /** @return \Generator<int, array{path: string}|array{directory: string, error: 'outside'|'unreadable'}> */
    private function traverse(Project $project, bool $includeExcluded, string $root): \Generator
    {
        $directories = [$root];
        while ([] !== $directories) {
            $directory = array_pop($directories);
            $entries = @scandir($directory);
            if (false === $entries) {
                yield ['directory' => $directory, 'error' => 'unreadable'];

                continue;
            }
            foreach ($entries as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }
                $path = Path::join($directory, $entry);
                if (is_dir($path)) {
                    if (\in_array($entry, ProjectPathPolicy::EXCLUDED_DIRECTORIES, true)) {
                        continue;
                    }
                    if (is_link($path)) {
                        if (!$this->realPathBelongsToProject($root, $path)) {
                            yield ['directory' => $path, 'error' => 'outside'];
                        }

                        continue;
                    }
                    if (!$includeExcluded && $this->fileScope->isDirectoryExcluded($project, $path)) {
                        continue;
                    }
                    if (!is_readable($path)) {
                        yield ['directory' => $path, 'error' => 'unreadable'];

                        continue;
                    }
                    $directories[] = $path;

                    continue;
                }
                if (!is_file($path) || null === $this->languageId($path)) {
                    continue;
                }
                if (!$includeExcluded && $this->fileScope->isExcluded($project, $path)) {
                    continue;
                }

                yield ['path' => Path::canonicalize($path)];
            }
        }
    }

    public function languageId(string $path): ?string
    {
        $basename = basename($path);
        if (str_starts_with($basename, '.env')) {
            return 'dotenv';
        }
        if (\in_array($basename, self::LOCK_FILES, true)) {
            return null;
        }

        $extension = Path::getExtension($path, true);
        if (\in_array($extension, ['js', 'mjs', 'ts'], true) && !str_contains('/'.Path::canonicalize($path), '/assets/')) {
            return null;
        }

        return self::LANGUAGE_IDS[$extension] ?? null;
    }

    public function gitignoreExcluded(string $rootPath, string $path): bool
    {
        if ('dotenv' === $this->languageId($path) && Path::canonicalize($rootPath) === \dirname(Path::canonicalize($path))) {
            return false;
        }

        return $this->gitignore->isIgnored($rootPath, $path);
    }

    public function isExcluded(Project $project, string $path): bool
    {
        return $this->fileScope->isExcluded($project, $path);
    }

    public function isDirectoryExcluded(Project $project, string $path): bool
    {
        return $this->fileScope->isDirectoryExcluded($project, $path);
    }

    public function belongsToProject(Project $project, string $path): bool
    {
        $relativePath = $this->relativePath($project, $path);
        if (null === $relativePath) {
            return false;
        }

        foreach (explode('/', $relativePath) as $part) {
            if (\in_array($part, ProjectPathPolicy::EXCLUDED_DIRECTORIES, true)) {
                return false;
            }
        }

        return true;
    }

    public function relativePath(Project $project, string $path): ?string
    {
        $root = Path::canonicalize($project->rootPath);
        $path = Path::canonicalize($path);
        if (!Path::isBasePath($root, $path) || $root === $path) {
            return null;
        }

        return Path::makeRelative($path, $root);
    }

    private function realPathBelongsToProject(string $projectRoot, string $path): bool
    {
        $realRoot = realpath($projectRoot);
        $realPath = realpath($path);
        if (false === $realRoot || false === $realPath) {
            return false;
        }
        $realRoot = Path::canonicalize($realRoot);
        $realPath = Path::canonicalize($realPath);

        return $realRoot !== $realPath && Path::isBasePath($realRoot, $realPath);
    }
}
