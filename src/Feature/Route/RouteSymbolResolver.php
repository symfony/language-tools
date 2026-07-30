<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;

final class RouteSymbolResolver
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly RouteReferenceExtractor $referenceExtractor,
        private readonly PhpRouteDeclarationExtractor $declarationExtractor,
    ) {
    }

    public function resolve(string $uri, string $text, Position $position): ?RouteSymbol
    {
        $offset = $this->positionConverter->toByteOffset($text, $position);
        $reference = $this->referenceExtractor->at($text, $offset);
        if (null !== $reference) {
            return new RouteSymbol($reference->name(), $reference->range());
        }

        foreach ($this->declarationExtractor->extract($uri, $text) as $declaration) {
            $start = $this->positionConverter->toByteOffset($text, $declaration->range()->start());
            $end = $this->positionConverter->toByteOffset($text, $declaration->range()->end());
            if ($offset >= $start && $offset <= $end) {
                return new RouteSymbol($declaration->name(), $declaration->range());
            }
        }

        return null;
    }
}
