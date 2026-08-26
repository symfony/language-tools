<?php

namespace Symfony\Lsp\Index;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
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
        $directory = $project->rootPath();
        if (!is_dir($directory)) {
            return;
        }

        $dotenvPaths = [];
        $files = (new Finder())
            ->files()
            ->in($directory)
            ->exclude(ProjectPathPolicy::EXCLUDED_DIRECTORIES)
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->ignoreUnreadableDirs()
            ->filter(fn (\SplFileInfo $file): bool => null !== $this->languageId($file->getPathname()));
        foreach ($this->gitignore->filter($files, $directory) as $path) {
            if (!$includeExcluded && $this->fileScope->isExcluded($project, $path)) {
                continue;
            }
            if ('dotenv' === $this->languageId($path)) {
                $dotenvPaths[$path] = true;
            }
            yield $path;
        }

        foreach (glob($directory.'/.env*') ?: [] as $path) {
            if (is_file($path) && !isset($dotenvPaths[$path]) && ($includeExcluded || !$this->fileScope->isExcluded($project, $path))) {
                yield $path;
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
        // Symfony reads project-root dotenv files even when they are gitignored
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
        $root = Path::canonicalize($project->rootPath());
        $path = Path::canonicalize($path);
        if (!Path::isBasePath($root, $path) || $root === $path) {
            return null;
        }

        return Path::makeRelative($path, $root);
    }
}
