<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
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
    ) {
    }

    public function resolve(string $uri, string $text, Position $position): ?RouteSymbol
    {
        $offset = $this->positionConverter->toByteOffset($text, $position);
        $extension = Path::getExtension($this->uriToPathConverter->convert($uri) ?? '', true);
        $reference = 'twig' === $extension
            ? $this->twigReferenceExtractor->at($text, $offset)
            : $this->phpReferenceExtractor->at($text, $offset);
        if (null !== $reference) {
            return new RouteSymbol($reference->name(), $reference->range());
        }

        $declarations = \in_array($extension, ['yaml', 'yml'], true)
            ? $this->yamlDeclarationExtractor->extract($uri, $text)
            : $this->phpDeclarationExtractor->extract($uri, $text);
        foreach ($declarations as $declaration) {
            $start = $this->positionConverter->toByteOffset($text, $declaration->range()->start());
            $end = $this->positionConverter->toByteOffset($text, $declaration->range()->end());
            if ($offset >= $start && $offset <= $end) {
                return new RouteSymbol($declaration->name(), $declaration->range());
            }
        }

        return null;
    }
}
