<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;

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
        private readonly ProjectPathResolver $pathResolver,
    ) {
    }

    public function name(): string
    {
        return 'routes';
    }

    public function begin(Project $project): void
    {
        $this->declarations[$project->rootPath()] = [];
        $this->references[$project->rootPath()] = [];
    }

    public function index(Project $project, SourceDocument $document): RouteSourceFacts
    {
        return $this->add($project, $this->extract($project, $document));
    }

    public function restore(Project $project, mixed $data): void
    {
        if (!$data instanceof RouteSourceFacts) {
            throw new \UnexpectedValueException('The cached route source facts are invalid.');
        }

        $this->add($project, $data);
    }

    public function finish(Project $project): void
    {
        $key = $project->rootPath();
        $this->declarationIndexes->forProject($project)->replace(...$this->declarations[$key]);
        $this->referenceIndexes->forProject($project)->replace(...$this->references[$key]);
        unset($this->declarations[$key], $this->references[$key]);
    }

    public function replace(Project $project, SourceDocument $document): RouteSourceFacts
    {
        $facts = $this->extract($project, $document);
        $this->declarationIndexes->forProject($project)->replaceSource($document->uri(), ...$facts->declarations());
        $this->referenceIndexes->forProject($project)->replaceSource($document->uri(), ...$facts->references());

        return $facts;
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof RouteSourceFacts) {
            throw new \UnexpectedValueException('The route source facts are invalid.');
        }

        return $data->declarations();
    }

    public function remove(Project $project, string $uri): void
    {
        $this->declarationIndexes->forProject($project)->removeSource($uri);
        $this->referenceIndexes->forProject($project)->removeSource($uri);
    }

    public function overlay(Project $project, Document $document): void
    {
        if ('yaml' === $document->languageId() && !$this->isRouteYaml($project, $document->uri())) {
            return;
        }

        $facts = $this->extract($project, new SourceDocument($document->uri(), $document->languageId(), $document->text()));
        $this->declarationIndexes->forProject($project)->replaceForUri($document->uri(), ...$facts->declarations());
        $this->referenceIndexes->forProject($project)->replaceForUri($document->uri(), ...$facts->references());
    }

    public function removeOverlay(Project $project, string $uri): void
    {
        $this->declarationIndexes->forProject($project)->removeOverlay($uri);
        $this->referenceIndexes->forProject($project)->removeOverlay($uri);
    }

    private function add(Project $project, RouteSourceFacts $facts): RouteSourceFacts
    {
        array_push($this->declarations[$project->rootPath()], ...$facts->declarations());
        array_push($this->references[$project->rootPath()], ...$facts->references());

        return $facts;
    }

    private function extract(Project $project, SourceDocument $document): RouteSourceFacts
    {
        $uri = $document->uri();
        $languageId = $document->languageId();
        $text = $document->text();
        $declarations = [];
        if ('php' === $languageId) {
            $declarations = $this->phpDeclarationExtractor->extract($uri, $text);
            $references = $this->phpReferenceExtractor->extract($text);
        } elseif ('twig' === $languageId) {
            $references = $this->twigReferenceExtractor->extract($text);
        } elseif ('yaml' === $languageId && $this->isRouteYaml($project, $uri)) {
            $declarations = $this->yamlDeclarationExtractor->extract($uri, $text);
            $references = [];
        } else {
            return new RouteSourceFacts($uri, [], []);
        }

        return new RouteSourceFacts($uri, $declarations, array_map(
            static fn (RouteReference $reference): RouteReferenceLocation => new RouteReferenceLocation(
                $reference->name(),
                $uri,
                $reference->range(),
            ),
            $references,
        ));
    }

    private function isRouteYaml(Project $project, string $uri): bool
    {
        $relativePath = $this->pathResolver->relative($project, $uri);
        if (null === $relativePath) {
            return false;
        }

        return str_starts_with($relativePath, 'config/routes/')
            || (str_starts_with($relativePath, 'config/routes.')
                && \in_array(Path::getExtension($relativePath, true), ['yaml', 'yml'], true));
    }
}
