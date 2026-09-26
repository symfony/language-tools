<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\PositionedRequest;

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

    public function resolve(PositionedRequest $request): ?RouteSymbol
    {
        $document = $request->source;
        $offset = $request->offset;
        $extension = Path::getExtension($this->uriToPathConverter->convert($document->uri) ?? '', true);
        $reference = 'twig' === $extension
            ? $this->twigReferenceExtractor->at($document, $offset)
            : $this->phpReferenceExtractor->at($document, $offset, $this->classIndexes->forProject($request->project));
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
