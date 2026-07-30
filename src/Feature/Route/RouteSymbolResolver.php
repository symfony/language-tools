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

    public function resolve(string $uri, string $text, Position $position): ?string
    {
        $offset = $this->positionConverter->toByteOffset($text, $position);
        $reference = $this->referenceExtractor->at($text, $offset);
        if (null !== $reference) {
            return $reference->name();
        }

        foreach ($this->declarationExtractor->extract($uri, $text) as $declaration) {
            $start = $this->positionConverter->toByteOffset($text, $declaration->range()->start());
            $end = $this->positionConverter->toByteOffset($text, $declaration->range()->end());
            if ($offset >= $start && $offset <= $end) {
                return $declaration->name();
            }
        }

        return null;
    }
}
