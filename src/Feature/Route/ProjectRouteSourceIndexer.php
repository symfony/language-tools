<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
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
        private readonly DocumentStore $documents,
        private readonly RouteDeclarationIndexRegistry $declarationIndexes,
        private readonly RouteReferenceIndexRegistry $referenceIndexes,
        private readonly PhpRouteDeclarationExtractor $phpDeclarationExtractor,
        private readonly YamlRouteDeclarationExtractor $yamlDeclarationExtractor,
        private readonly RouteReferenceExtractor $phpReferenceExtractor,
        private readonly TwigRouteReferenceExtractor $twigReferenceExtractor,
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
    public function updateOpenDocument(array $params): void
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return;
        }

        $document = $this->documents->get($textDocument['uri']);
        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $document || null === $project) {
            return;
        }

        $extension = strtolower(pathinfo((string) parse_url($document->uri(), \PHP_URL_PATH), \PATHINFO_EXTENSION));
        if (\in_array($extension, ['yaml', 'yml'], true) && !$this->isRouteYaml($project, $document->uri())) {
            return;
        }

        [$declarations, $references] = $this->extractDocument($document);
        $this->declarationIndexes->forProject($project)->replaceForUri($document->uri(), ...$declarations);
        $this->referenceIndexes->forProject($project)->replaceForUri($document->uri(), ...$references);
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function restoreClosedDocument(array $params): void
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return;
        }

        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null !== $project) {
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
        if (null === $project || !\is_string($path)) {
            return;
        }

        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        if (\in_array($extension, ['php', 'twig', 'yaml', 'yml'], true)) {
            $this->index($project);
        }
    }

    private function index(Project $project): void
    {
        $declarations = [];
        $references = [];
        foreach ($this->sourceFiles($project) as $path) {
            $text = file_get_contents($path);
            if (false === $text) {
                continue;
            }

            [$fileDeclarations, $fileReferences] = $this->extract(
                $this->uri($project, $path),
                strtolower(pathinfo($path, \PATHINFO_EXTENSION)),
                $text,
            );
            array_push($declarations, ...$fileDeclarations);
            array_push($references, ...$fileReferences);
        }

        $this->declarationIndexes->forProject($project)->replace(...$declarations);
        $this->referenceIndexes->forProject($project)->replace(...$references);
    }

    /**
     * @return array{list<RouteDeclaration>, list<RouteReferenceLocation>}
     */
    private function extractDocument(Document $document): array
    {
        $extension = strtolower(pathinfo((string) parse_url($document->uri(), \PHP_URL_PATH), \PATHINFO_EXTENSION));

        return $this->extract($document->uri(), $extension, $document->text());
    }

    /**
     * @return array{list<RouteDeclaration>, list<RouteReferenceLocation>}
     */
    private function extract(string $uri, string $extension, string $text): array
    {
        $declarations = [];
        if ('php' === $extension) {
            $declarations = $this->phpDeclarationExtractor->extract($uri, $text);
            $references = $this->phpReferenceExtractor->extract($text);
        } elseif ('twig' === $extension) {
            $references = $this->twigReferenceExtractor->extract($text);
        } elseif (\in_array($extension, ['yaml', 'yml'], true)) {
            $declarations = $this->yamlDeclarationExtractor->extract($uri, $text);
            $references = [];
        } else {
            return [[], []];
        }

        return [$declarations, array_map(
            static fn (RouteReference $reference): RouteReferenceLocation => new RouteReferenceLocation(
                $reference->name(),
                $uri,
                $reference->range(),
            ),
            $references,
        )];
    }

    /**
     * @return \Generator<int, string>
     */
    private function sourceFiles(Project $project): \Generator
    {
        yield from $this->ownedSourceFiles($project->rootPath());

        $configDirectory = $project->rootPath().'/config';
        if (is_dir($configDirectory)) {
            yield from $this->routeYamlFiles($configDirectory, true);
        }
    }

    /**
     * @return \Generator<int, string>
     */
    private function ownedSourceFiles(string $directory): \Generator
    {
        $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isDir()) {
                if (!$file->isLink() && !\in_array($file->getFilename(), self::EXCLUDED_DIRECTORIES, true)) {
                    yield from $this->ownedSourceFiles($file->getPathname());
                }

                continue;
            }

            if ($file->isFile() && \in_array(strtolower($file->getExtension()), ['php', 'twig'], true)) {
                yield $file->getPathname();
            }
        }
    }

    /**
     * @return \Generator<int, string>
     */
    private function routeYamlFiles(string $directory, bool $root = false): \Generator
    {
        $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isDir()) {
                if (!$file->isLink() && (!$root || 'routes' === $file->getFilename())) {
                    yield from $this->routeYamlFiles($file->getPathname());
                }

                continue;
            }

            $extension = strtolower($file->getExtension());
            if ($file->isFile()
                && \in_array($extension, ['yaml', 'yml'], true)
                && (!$root || str_starts_with($file->getBasename('.'.$extension), 'routes'))
            ) {
                yield $file->getPathname();
            }
        }
    }

    private function isRouteYaml(Project $project, string $uri): bool
    {
        $path = parse_url($uri, \PHP_URL_PATH);
        if (!\is_string($path)) {
            return false;
        }

        $root = rtrim(str_replace('\\', '/', $project->rootPath()), '/');
        $relativePath = ltrim(substr(str_replace('\\', '/', rawurldecode($path)), \strlen($root)), '/');

        return str_starts_with($relativePath, 'config/routes/')
            || (str_starts_with($relativePath, 'config/routes.')
                && \in_array(strtolower(pathinfo($relativePath, \PATHINFO_EXTENSION)), ['yaml', 'yml'], true));
    }

    private function uri(Project $project, string $path): string
    {
        $relativePath = substr(str_replace('\\', '/', $path), \strlen(rtrim(str_replace('\\', '/', $project->rootPath()), '/')) + 1);
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));

        return rtrim($project->rootUri(), '/').'/'.$encodedPath;
    }
}
