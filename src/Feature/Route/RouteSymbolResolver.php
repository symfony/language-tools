<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\UriToPathConverter;

final class RouteSymbolResolver
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly RouteReferenceExtractor $phpReferenceExtractor,
        private readonly TwigRouteReferenceExtractor $twigReferenceExtractor,
        private readonly PhpRouteDeclarationExtractor $phpDeclarationExtractor,
        private readonly YamlRouteDeclarationExtractor $yamlDeclarationExtractor,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
    ) {
    }

    public function resolve(Project $project, SourceDocument $document, Position $position): ?RouteSymbol
    {
        $offset = $this->positionConverter->toByteOffset($document->text, $position);
        $extension = Path::getExtension($this->uriToPathConverter->convert($document->uri) ?? '', true);
        $reference = 'twig' === $extension
            ? $this->twigReferenceExtractor->at($document, $offset)
            : $this->phpReferenceExtractor->at($document, $offset, $this->classIndexes->forProject($project));
        if (null !== $reference) {
            return new RouteSymbol($reference->name, $reference->range);
        }

        $declarations = \in_array($extension, ['yaml', 'yml'], true)
            ? $this->yamlDeclarationExtractor->extract($document)
            : $this->phpDeclarationExtractor->extract($document);
        foreach ($declarations as $declaration) {
            $start = $this->positionConverter->toByteOffset($document->text, $declaration->range->start);
            $end = $this->positionConverter->toByteOffset($document->text, $declaration->range->end);
            if ($offset >= $start && $offset <= $end) {
                return new RouteSymbol($declaration->name, $declaration->range);
            }
        }

        return null;
    }
}
