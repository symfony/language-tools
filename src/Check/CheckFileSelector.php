<?php

namespace Symfony\Lsp\Check;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Glob;
use Symfony\Lsp\Index\SourceFileEnumerator;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectConfiguration;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

/** @phpstan-type CompiledSelector array{selector: string, type: 'pattern', regex: string}|array{selector: string, type: 'directory'|'file'|'other', path: string} */
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
        $compiled = $this->compileSelectors($workspace, $selectors);
        $includeExcluded = [] !== $compiled;
        $selected = [];
        $matches = array_fill(0, \count($compiled), false);
        $rejections = [];

        foreach ($this->projects->all() as $project) {
            foreach ($this->files->entries($project, $includeExcluded) as $entry) {
                if (isset($entry['directory'])) {
                    if (!$includeExcluded) {
                        throw new InvalidConfigurationException($this->directoryError($project, $entry['directory'], $entry['error']));
                    }

                    continue;
                }

                $path = Path::canonicalize($entry['path']);
                $uri = $this->uriToPathConverter->toUri($path);
                if ($this->projects->forDocumentUri($uri)?->rootPath !== $project->rootPath) {
                    continue;
                }
                $workspacePath = $this->projectConfiguration->workspaceRelativePath($project, $path);
                $selectorIndexes = $this->matchingSelectors($compiled, $path, $workspacePath);
                if ($includeExcluded && [] === $selectorIndexes) {
                    continue;
                }
                $projectPath = $this->files->relativePath($project, $path);
                $languageId = $this->files->languageId($path);
                if (null === $projectPath || null === $languageId) {
                    continue;
                }

                $rejection = $this->candidateRejection($project, $path, $workspacePath);
                if (null !== $rejection) {
                    if (!$includeExcluded) {
                        throw new InvalidConfigurationException($rejection);
                    }
                    foreach ($selectorIndexes as $selectorIndex) {
                        $rejections[$selectorIndex] ??= $rejection;
                    }

                    continue;
                }

                $file = new CheckFile(
                    $project,
                    $path,
                    $projectPath,
                    $workspacePath,
                    $uri,
                    $languageId,
                    $this->files->isExcluded($project, $path),
                );
                $selected[$path] = $file;
                foreach ($selectorIndexes as $selectorIndex) {
                    $matches[$selectorIndex] = true;
                }
            }
        }

        if ([] === $compiled) {
            if ([] === $selected) {
                throw new InvalidConfigurationException('No recognized application-owned files were found in the discovered Symfony projects.');
            }
        } else {
            foreach ($compiled as $index => $selector) {
                if (isset($rejections[$index])) {
                    throw new InvalidConfigurationException($rejections[$index]);
                }
                if (!$matches[$index]) {
                    throw new InvalidConfigurationException($this->selectionError($selector));
                }
            }
        }

        uasort($selected, static fn (CheckFile $left, CheckFile $right): int => strcmp($left->workspacePath, $right->workspacePath));

        return array_values($selected);
    }

    /**
     * @param list<string> $selectors
     *
     * @return list<CompiledSelector>
     */
    private function compileSelectors(string $workspace, array $selectors): array
    {
        $compiled = [];
        foreach ($selectors as $selector) {
            if ($this->isPattern($selector)) {
                $pattern = str_replace('\\', '/', $selector);
                $compiled[] = [
                    'selector' => $selector,
                    'type' => 'pattern',
                    'regex' => $this->patternRegex($pattern),
                ];

                continue;
            }

            $path = Path::canonicalize(Path::isAbsolute($selector) ? $selector : Path::join($workspace, $selector));
            $this->assertInsideWorkspace($workspace, $path, $selector);
            $compiled[] = [
                'selector' => $selector,
                'type' => is_file($path) ? 'file' : (is_dir($path) ? 'directory' : 'other'),
                'path' => $path,
            ];
        }

        return $compiled;
    }

    /**
     * @param list<CompiledSelector> $selectors
     *
     * @return list<int>
     */
    private function matchingSelectors(array $selectors, string $path, string $workspacePath): array
    {
        $matches = [];
        foreach ($selectors as $index => $selector) {
            if ('pattern' === $selector['type']) {
                if (1 === preg_match($selector['regex'], str_replace('\\', '/', $workspacePath))) {
                    $matches[] = $index;
                }

                continue;
            }
            if ('file' === $selector['type'] && $selector['path'] === $path) {
                $matches[] = $index;
            } elseif ('directory' === $selector['type'] && Path::isBasePath($selector['path'], $path)) {
                $matches[] = $index;
            }
        }

        return $matches;
    }

    /** @param CompiledSelector $selector */
    private function selectionError(array $selector): string
    {
        if ('pattern' === $selector['type']) {
            return \sprintf('The path pattern "%s" did not select any recognized application-owned files.', $selector['selector']);
        }

        $path = $selector['path'];
        if (!file_exists($path) && !is_link($path)) {
            return \sprintf('The selected path "%s" does not exist.', $selector['selector']);
        }
        if (!is_readable($path)) {
            return \sprintf('The selected path "%s" is unreadable.', $selector['selector']);
        }
        if (is_file($path)) {
            $uri = $this->uriToPathConverter->toUri($path);
            $project = $this->projects->forDocumentUri($uri);
            if (null === $project) {
                return \sprintf('The selected file "%s" is outside every discovered Symfony project.', $selector['selector']);
            }
            if (!$this->files->belongsToProject($project, $path)) {
                return \sprintf('The selected file "%s" is in an excluded dependency or cache directory.', $selector['selector']);
            }
            if ($this->files->gitignoreExcluded($project->rootPath, $path)) {
                return \sprintf('The selected file "%s" is ignored by the project .gitignore rules.', $selector['selector']);
            }
            if (null === $this->files->languageId($path)) {
                return \sprintf('The selected file "%s" has an unsupported language or file type.', $selector['selector']);
            }
        }

        return \sprintf('The selected path "%s" did not contain any recognized application-owned files.', $selector['selector']);
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

    private function candidateRejection(Project $project, string $path, string $workspacePath): ?string
    {
        if (!is_readable($path)) {
            return \sprintf('The application file "%s" is unreadable.', $workspacePath);
        }
        if (!$this->realPathBelongsToProject($project->rootPath, $path)) {
            return \sprintf('The application file "%s" resolves outside its Symfony project.', $workspacePath);
        }

        return null;
    }

    private function directoryError(Project $project, string $directory, string $error): string
    {
        $path = $project->rootPath === $directory ? '.' : $this->files->relativePath($project, $directory);

        return 'outside' === $error
            ? \sprintf('The application directory "%s" resolves outside its Symfony project.', $path)
            : \sprintf('The application directory "%s" is unreadable.', $path);
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

    private function patternRegex(string $pattern): string
    {
        $compiled = '';
        $length = \strlen($pattern);
        for ($index = 0; $index < $length; ++$index) {
            if ('*' !== $pattern[$index] || '*' !== ($pattern[$index + 1] ?? null)) {
                $compiled .= $pattern[$index];

                continue;
            }

            $previous = $pattern[$index - 1] ?? null;
            $next = $pattern[$index + 2] ?? null;
            $compiled .= (0 === $index && '/' === $next) || ('/' === $previous && (null === $next || '/' === $next))
                ? '**'
                : "\0";
            ++$index;
        }

        return str_replace("\0", '.*', Glob::toRegex($compiled, false, true, '~'));
    }

    private function isPattern(string $selector): bool
    {
        return str_contains($selector, '*') || str_contains($selector, '?');
    }
}
