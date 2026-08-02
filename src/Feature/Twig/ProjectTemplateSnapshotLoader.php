<?php

namespace Symfony\Lsp\Feature\Twig;

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
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
            if (!$file instanceof \SplFileInfo || $file->isLink()) {
                continue;
            }
            if ($file->isDir()) {
                yield from $this->files($file->getPathname());
            } elseif ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }

    private function absolute(string $path): bool
    {
        return str_starts_with($path, '/') || 1 === preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
