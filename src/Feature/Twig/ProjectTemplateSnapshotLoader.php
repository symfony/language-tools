<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\SnapshotSection;

final class ProjectTemplateSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(
        private readonly TemplateIndexRegistry $indexes,
        private readonly UriToPathConverter $uriToPathConverter,
    ) {
    }

    public function section(): string
    {
        return 'twig';
    }

    public function load(Project $project, SnapshotSection $section): void
    {
        $this->indexes->forProject($project)->replaceGlobals($section->strings('globals'));
        $templates = [];
        foreach ($section->items('paths', 'namespace', 'path') as $loaderPath) {
            $path = $loaderPath->path('path');
            $path = Path::isAbsolute($path)
                ? Path::canonicalize($path)
                : Path::join($project->rootPath, $path);
            if (!is_dir($path)) {
                continue;
            }
            $namespace = $loaderPath->string('namespace');
            foreach ($this->files($path) as $file) {
                $relative = Path::makeRelative($file, $path);
                $name = '(None)' === $namespace ? $relative : $namespace.'/'.$relative;
                $templates[] = new TemplateDeclaration(
                    $name,
                    $this->uriToPathConverter->toUri($file),
                    new Range(new Position(0, 0), new Position(0, 0)),
                );
            }
        }
        $this->indexes->forProject($project)->replaceRuntime($section->complete(), ...$templates);
    }

    /** @return \Generator<int, string> */
    private function files(string $directory): \Generator
    {
        $files = (new Finder())
            ->files()
            ->in($directory)
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->ignoreUnreadableDirs()
            ->filter(static fn (\SplFileInfo $file): bool => !$file->isLink());
        foreach ($files as $file) {
            yield $file->getPathname();
        }
    }
}
