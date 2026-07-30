<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Project\Project;

final class ProjectRouteSourceIndexer implements SourceIndexProviderInterface
{
    /** @var array<string, list<RouteDeclaration>> */
    private array $declarations = [];

    /** @var array<string, list<RouteReferenceLocation>> */
    private array $references = [];

    public function __construct(
        private readonly RouteDeclarationIndexRegistry $declarationIndexes,
        private readonly RouteReferenceIndexRegistry $referenceIndexes,
        private readonly PhpRouteDeclarationExtractor $phpDeclarationExtractor,
        private readonly YamlRouteDeclarationExtractor $yamlDeclarationExtractor,
        private readonly RouteReferenceExtractor $phpReferenceExtractor,
        private readonly TwigRouteReferenceExtractor $twigReferenceExtractor,
    ) {
    }

    public function begin(Project $project): void
    {
        $this->declarations[$project->rootPath()] = [];
        $this->references[$project->rootPath()] = [];
    }

    public function index(Project $project, SourceDocument $document): void
    {
        if ('yaml' === $document->languageId() && !$this->isRouteYaml($project, $document->uri())) {
            return;
        }

        [$declarations, $references] = $this->extract(
            $document->uri(),
            $document->languageId(),
            $document->text(),
        );
        array_push($this->declarations[$project->rootPath()], ...$declarations);
        array_push($this->references[$project->rootPath()], ...$references);
    }

    public function finish(Project $project): void
    {
        $key = $project->rootPath();
        $this->declarationIndexes->forProject($project)->replace(...$this->declarations[$key]);
        $this->referenceIndexes->forProject($project)->replace(...$this->references[$key]);
        unset($this->declarations[$key], $this->references[$key]);
    }

    public function overlay(Project $project, Document $document): void
    {
        if ('yaml' === $document->languageId() && !$this->isRouteYaml($project, $document->uri())) {
            return;
        }

        [$declarations, $references] = $this->extract(
            $document->uri(),
            $document->languageId(),
            $document->text(),
        );
        $this->declarationIndexes->forProject($project)->replaceForUri($document->uri(), ...$declarations);
        $this->referenceIndexes->forProject($project)->replaceForUri($document->uri(), ...$references);
    }

    public function removeOverlay(Project $project, string $uri): void
    {
        $this->declarationIndexes->forProject($project)->removeOverlay($uri);
        $this->referenceIndexes->forProject($project)->removeOverlay($uri);
    }

    /**
     * @return array{list<RouteDeclaration>, list<RouteReferenceLocation>}
     */
    private function extract(string $uri, string $languageId, string $text): array
    {
        $declarations = [];
        if ('php' === $languageId) {
            $declarations = $this->phpDeclarationExtractor->extract($uri, $text);
            $references = $this->phpReferenceExtractor->extract($text);
        } elseif ('twig' === $languageId) {
            $references = $this->twigReferenceExtractor->extract($text);
        } elseif ('yaml' === $languageId) {
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
}
