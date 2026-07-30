<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class ProjectRouteSourceIndexer
{
    private const EXCLUDED_DIRECTORIES = [
        '.git',
        'node_modules',
        'var',
        'vendor',
    ];

    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly RouteDeclarationIndexRegistry $declarationIndexes,
        private readonly PhpRouteDeclarationExtractor $extractor,
    ) {
    }

    public function indexAll(): void
    {
        foreach ($this->projects->all() as $project) {
            $this->index($project);
        }
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function refreshAfterSave(array $params): void
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return;
        }

        $path = parse_url($textDocument['uri'], \PHP_URL_PATH);
        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null !== $project && \is_string($path) && 'php' === strtolower(pathinfo($path, \PATHINFO_EXTENSION))) {
            $this->index($project);
        }
    }

    private function index(Project $project): void
    {
        $declarations = [];
        foreach ($this->phpFiles($project->rootPath()) as $path) {
            $text = file_get_contents($path);
            if (false === $text) {
                continue;
            }

            array_push($declarations, ...$this->extractor->extract($this->uri($project, $path), $text));
        }

        $this->declarationIndexes->forProject($project)->replace(...$declarations);
    }

    /**
     * @return \Generator<int, string>
     */
    private function phpFiles(string $directory): \Generator
    {
        $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isDir()) {
                if (!$file->isLink() && !\in_array($file->getFilename(), self::EXCLUDED_DIRECTORIES, true)) {
                    yield from $this->phpFiles($file->getPathname());
                }

                continue;
            }

            if ($file->isFile() && 'php' === strtolower($file->getExtension())) {
                yield $file->getPathname();
            }
        }
    }

    private function uri(Project $project, string $path): string
    {
        $relativePath = substr(str_replace('\\', '/', $path), \strlen(rtrim(str_replace('\\', '/', $project->rootPath()), '/')) + 1);
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));

        return rtrim($project->rootUri(), '/').'/'.$encodedPath;
    }
}
