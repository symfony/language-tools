<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ProjectTemplateSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly TemplateIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'twig';
    }

    public function load(Project $project, array $snapshot): void
    {
        $sections = $snapshot['sections'] ?? null;
        $section = \is_array($sections) ? ($sections['twig'] ?? null) : null;
        if (!\is_array($section) || !\is_array($section['paths'] ?? null)) {
            return;
        }
        $globals = \is_array($section['globals'] ?? null) ? array_values(array_filter($section['globals'], 'is_string')) : [];
        $this->indexes->forProject($project)->replaceGlobals($globals);
        $templates = [];
        foreach ($section['paths'] as $loaderPath) {
            if (!\is_array($loaderPath) || !\is_string($loaderPath['namespace'] ?? null) || !\is_string($loaderPath['path'] ?? null)) {
                continue;
            }
            $path = $loaderPath['path'];
            if (!$this->absolute($path)) {
                $path = $project->rootPath().'/'.$path;
            }
            if (!is_dir($path)) {
                continue;
            }
            foreach ($this->files($path) as $file) {
                $relative = ltrim(substr(str_replace('\\', '/', $file), \strlen(rtrim(str_replace('\\', '/', $path), '/'))), '/');
                $namespace = $loaderPath['namespace'];
                $name = '(None)' === $namespace ? $relative : $namespace.'/'.$relative;
                $templates[] = new TemplateDeclaration(
                    $name,
                    'file://'.str_replace(' ', '%20', str_replace('\\', '/', $file)),
                    new Range(new Position(0, 0), new Position(0, 0)),
                );
            }
        }
        $this->indexes->forProject($project)->replaceRuntime(
            true === ($section['complete'] ?? null),
            ...$templates,
        );
    }

    /** @return \Generator<int, string> */
    private function files(string $directory): \Generator
    {
        $files = (new Finder())
            ->files()
            ->in($directory)
            ->ignoreDotFiles(false)
            ->filter(static fn (\SplFileInfo $file): bool => !$file->isLink());
        foreach ($files as $file) {
            yield $file->getPathname();
        }
    }

    private function absolute(string $path): bool
    {
        return Path::isAbsolute($path);
    }
}
