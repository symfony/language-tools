<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;

final class RouteSymbolResolver
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly RouteReferenceExtractor $referenceExtractor,
        private readonly PhpRouteDeclarationExtractor $phpDeclarationExtractor,
        private readonly YamlRouteDeclarationExtractor $yamlDeclarationExtractor,
    ) {
    }

    public function resolve(string $uri, string $text, Position $position): ?RouteSymbol
    {
        $offset = $this->positionConverter->toByteOffset($text, $position);
        $reference = $this->referenceExtractor->at($text, $offset);
        if (null !== $reference) {
            return new RouteSymbol($reference->name(), $reference->range());
        }

        $extension = strtolower(pathinfo((string) parse_url($uri, \PHP_URL_PATH), \PATHINFO_EXTENSION));
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
