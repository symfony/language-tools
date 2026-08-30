<?php

namespace Symfony\Lsp\Check;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Index\SourceFileEnumerator;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectConfiguration;
use Symfony\Lsp\Project\ProjectPathPolicy;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

final class CheckFileSelector
{
    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly SourceFileEnumerator $files,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly ProjectConfiguration $projectConfiguration,
    ) {
    }

    /**
     * @param list<string> $selectors
     *
     * @return list<CheckFile>
     */
    public function select(string $workspace, array $selectors): array
    {
        $workspace = Path::canonicalize($workspace);
        if ([] === $selectors) {
            $candidates = $this->candidates($workspace, false);
            if ([] === $candidates) {
                throw new InvalidConfigurationException('No recognized application-owned files were found in the discovered Symfony projects.');
            }

            return array_values($candidates);
        }

        $selected = [];
        foreach ($selectors as $selector) {
            $matches = $this->matches($workspace, $selector, $this->candidates($workspace, true, $selector));
            if ([] === $matches) {
                throw new InvalidConfigurationException($this->selectionError($workspace, $selector));
            }
            foreach ($matches as $path => $file) {
                $selected[$path] = $file;
            }
        }
        uasort($selected, static fn (CheckFile $left, CheckFile $right): int => strcmp($left->workspacePath, $right->workspacePath));

        return array_values($selected);
    }

    /** @return array<string, CheckFile> */
    private function candidates(string $workspace, bool $includeExcluded, ?string $selector = null): array
    {
        $candidates = [];
        foreach ($this->projects->all() as $project) {
            if (!$includeExcluded) {
                $this->assertReadableDirectories($project);
            }
            foreach ($this->files->files($project, $includeExcluded) as $path) {
                $path = Path::canonicalize($path);
                $uri = $this->uriToPathConverter->toUri($path);
                if ($this->projects->forDocumentUri($uri)?->rootPath !== $project->rootPath) {
                    continue;
                }
                $projectPath = $this->files->relativePath($project, $path);
                $workspacePath = $this->projectConfiguration->workspaceRelativePath($project, $path);
                if (null !== $selector && !$this->candidateMatchesSelector($workspace, $selector, $path, $workspacePath)) {
                    continue;
                }
                $languageId = $this->files->languageId($path);
                if (null === $projectPath || null === $languageId) {
                    continue;
                }
                if (!is_readable($path)) {
                    throw new InvalidConfigurationException(\sprintf('The application file "%s" is unreadable.', $this->projectConfiguration->workspaceRelativePath($project, $path)));
                }
                if (!$this->realPathBelongsToProject($project->rootPath, $path)) {
                    throw new InvalidConfigurationException(\sprintf('The application file "%s" resolves outside its Symfony project.', $this->projectConfiguration->workspaceRelativePath($project, $path)));
                }
                $candidates[$path] = new CheckFile(
                    $project,
                    $path,
                    $projectPath,
                    $workspacePath,
                    $uri,
                    $languageId,
                    $this->files->isExcluded($project, $path),
                );
            }
        }
        uasort($candidates, static fn (CheckFile $left, CheckFile $right): int => strcmp($left->workspacePath, $right->workspacePath));

        return $candidates;
    }

    /**
     * @param array<string, CheckFile> $candidates
     *
     * @return array<string, CheckFile>
     */
    private function matches(string $workspace, string $selector, array $candidates): array
    {
        if ($this->isPattern($selector)) {
            $pattern = str_replace('\\', '/', $selector);
            $matches = array_filter(
                $candidates,
                fn (CheckFile $file): bool => preg_match($this->patternRegex($pattern), str_replace('\\', '/', $file->workspacePath)) > 0,
            );

            return $matches;
        }

        $path = Path::canonicalize(Path::isAbsolute($selector) ? $selector : Path::join($workspace, $selector));
        $this->assertInsideWorkspace($workspace, $path, $selector);
        if (is_file($path)) {
            return isset($candidates[$path]) ? [$path => $candidates[$path]] : [];
        }
        if (is_dir($path)) {
            return array_filter(
                $candidates,
                static fn (CheckFile $file): bool => Path::isBasePath($path, $file->path),
            );
        }

        return [];
    }

    private function selectionError(string $workspace, string $selector): string
    {
        if ($this->isPattern($selector)) {
            return \sprintf('The path pattern "%s" did not select any recognized application-owned files.', $selector);
        }

        $path = Path::canonicalize(Path::isAbsolute($selector) ? $selector : Path::join($workspace, $selector));
        if (!file_exists($path) && !is_link($path)) {
            return \sprintf('The selected path "%s" does not exist.', $selector);
        }
        if (!is_readable($path)) {
            return \sprintf('The selected path "%s" is unreadable.', $selector);
        }
        if (is_file($path)) {
            $uri = $this->uriToPathConverter->toUri($path);
            $project = $this->projects->forDocumentUri($uri);
            if (null === $project) {
                return \sprintf('The selected file "%s" is outside every discovered Symfony project.', $selector);
            }
            if (!$this->files->belongsToProject($project, $path)) {
                return \sprintf('The selected file "%s" is in an excluded dependency or cache directory.', $selector);
            }
            if ($this->files->gitignoreExcluded($project->rootPath, $path)) {
                return \sprintf('The selected file "%s" is ignored by the project .gitignore rules.', $selector);
            }
            if (null === $this->files->languageId($path)) {
                return \sprintf('The selected file "%s" has an unsupported language or file type.', $selector);
            }
        }

        return \sprintf('The selected path "%s" did not contain any recognized application-owned files.', $selector);
    }

    private function assertInsideWorkspace(string $workspace, string $path, string $selector): void
    {
        if ($workspace !== $path && !Path::isBasePath($workspace, $path)) {
            throw new InvalidConfigurationException(\sprintf('The selected path "%s" is outside the workspace.', $selector));
        }
        $realPath = realpath($path);
        $realWorkspace = realpath($workspace);
        if (false !== $realPath && false !== $realWorkspace) {
            $realPath = Path::canonicalize($realPath);
            $realWorkspace = Path::canonicalize($realWorkspace);
            if ($realWorkspace !== $realPath && !Path::isBasePath($realWorkspace, $realPath)) {
                throw new InvalidConfigurationException(\sprintf('The selected path "%s" resolves outside the workspace.', $selector));
            }
        }
    }

    private function candidateMatchesSelector(string $workspace, string $selector, string $path, string $workspacePath): bool
    {
        if ($this->isPattern($selector)) {
            return preg_match($this->patternRegex(str_replace('\\', '/', $selector)), str_replace('\\', '/', $workspacePath)) > 0;
        }

        $selectedPath = Path::canonicalize(Path::isAbsolute($selector) ? $selector : Path::join($workspace, $selector));
        if (is_file($selectedPath)) {
            return $selectedPath === $path;
        }

        return is_dir($selectedPath) && Path::isBasePath($selectedPath, $path);
    }

    private function assertReadableDirectories(Project $project): void
    {
        $directories = [$project->rootPath];
        while ([] !== $directories) {
            $directory = array_pop($directories);
            $entries = @scandir($directory);
            if (false === $entries) {
                throw new InvalidConfigurationException(\sprintf('The application directory "%s" is unreadable.', $project->rootPath === $directory ? '.' : $this->files->relativePath($project, $directory)));
            }
            foreach ($entries as $entry) {
                if ('.' === $entry || '..' === $entry || \in_array($entry, ProjectPathPolicy::EXCLUDED_DIRECTORIES, true)) {
                    continue;
                }
                $path = Path::join($directory, $entry);
                if (!is_dir($path)) {
                    continue;
                }
                if ($this->files->isDirectoryExcluded($project, $path)) {
                    continue;
                }
                if ($this->files->gitignoreExcluded($project->rootPath, $path)) {
                    continue;
                }
                if (is_link($path)) {
                    if (!$this->realPathBelongsToProject($project->rootPath, $path)) {
                        throw new InvalidConfigurationException(\sprintf('The application directory "%s" resolves outside its Symfony project.', $this->files->relativePath($project, $path)));
                    }
                    continue;
                }
                if (!is_readable($path)) {
                    throw new InvalidConfigurationException(\sprintf('The application directory "%s" is unreadable.', $this->files->relativePath($project, $path)));
                }
                $directories[] = $path;
            }
        }
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

    private function isPattern(string $selector): bool
    {
        return str_contains($selector, '*') || str_contains($selector, '?');
    }

    private function patternRegex(string $pattern): string
    {
        $regex = '';
        $length = \strlen($pattern);
        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $pattern[$offset];
            if ('*' === $character && '*' === ($pattern[$offset + 1] ?? null)) {
                if ('/' === ($pattern[$offset + 2] ?? null)) {
                    $regex .= '(?:.*/)?';
                    $offset += 2;
                } else {
                    $regex .= '.*';
                    ++$offset;
                }
                continue;
            }
            if ('*' === $character) {
                $regex .= '[^/]*';
                continue;
            }
            if ('?' === $character) {
                $regex .= '[^/]';
                continue;
            }
            $regex .= preg_quote($character, '{');
        }

        return '{^'.$regex.'$}D';
    }
}
