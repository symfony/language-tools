<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;

/** @extends AbstractSourceIndexer<RouteSourceFacts> */
final class ProjectRouteSourceIndexer extends AbstractSourceIndexer
{
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

    public function payloadClasses(): array
    {
        return [RouteDeclaration::class, RouteReferenceLocation::class, RouteSourceFacts::class];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof RouteSourceFacts) {
            throw new \UnexpectedValueException('The route source facts are invalid.');
        }

        return $data->declarations();
    }

    protected function factsClass(): string
    {
        return RouteSourceFacts::class;
    }

    protected function sourceIndex(Project $project): RouteSourceIndexAdapter
    {
        return new RouteSourceIndexAdapter(
            $this->declarationIndexes->forProject($project),
            $this->referenceIndexes->forProject($project),
        );
    }

    protected function extract(Project $project, SourceDocument $document): RouteSourceFacts
    {
        $uri = $document->uri;
        $languageId = $document->languageId;
        $text = $document->text;
        $declarations = [];
        if ('php' === $languageId) {
            $declarations = $this->phpDeclarationExtractor->extract($uri, $text);
            $references = $this->phpReferenceExtractor->extractCandidates($text);
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
                $reference->controllerClass(),
            ),
            $references,
        ));
    }

    protected function supportsOverlay(Project $project, Document $document): bool
    {
        return 'yaml' !== $document->languageId || $this->isRouteYaml($project, $document->uri);
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
