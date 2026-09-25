<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;

/** @extends AbstractSourceIndexer<RouteSourceFacts> */
final class ProjectRouteSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(
        RouteSourceIndexRegistry $sourceIndexes,
        private readonly PhpRouteDeclarationExtractor $phpDeclarationExtractor,
        private readonly YamlRouteDeclarationExtractor $yamlDeclarationExtractor,
        private readonly RouteReferenceExtractor $phpReferenceExtractor,
        private readonly TwigRouteReferenceExtractor $twigReferenceExtractor,
        private readonly ProjectPathResolver $pathResolver,
    ) {
        parent::__construct($sourceIndexes, 'routes', RouteSourceFacts::class);
    }

    protected function payloadElementClasses(): array
    {
        return [RouteDeclaration::class, RouteReference::class];
    }

    protected function extract(Project $project, SourceDocument $document): RouteSourceFacts
    {
        $declarations = [];
        if ('php' === $document->languageId) {
            $declarations = $this->phpDeclarationExtractor->extract($document);
            $references = $this->phpReferenceExtractor->extractCandidates($document);
        } elseif ('twig' === $document->languageId) {
            $references = $this->twigReferenceExtractor->extract($document);
        } elseif ('yaml' === $document->languageId && $this->isRouteYaml($project, $document->uri)) {
            $declarations = $this->yamlDeclarationExtractor->extract($document);
            $references = [];
        } else {
            return new RouteSourceFacts($document->uri, [], []);
        }

        return new RouteSourceFacts($document->uri, $declarations, $references);
    }

    protected function refreshRelevantFacts(SourceFactsInterface $facts): array
    {
        return $facts->declarations;
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): RouteSourceFacts
    {
        return new RouteSourceFacts($current->uri, $healthy->declarations, $current->references);
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
