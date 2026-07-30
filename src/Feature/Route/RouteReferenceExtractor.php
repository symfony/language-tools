<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class RouteReferenceExtractor
{
    private const METHODS = [
        'generate',
        'generateUrl',
        'redirectToRoute',
    ];

    public function __construct(
        private readonly PositionConverter $positionConverter,
    ) {
    }

    /**
     * @return list<RouteReference>
     */
    public function extract(string $text): array
    {
        preg_match_all(
            '/(?:->|::)('.implode('|', self::METHODS).')\s*\(\s*([\'\"])([^\'\"]+)\2/s',
            $text,
            $matches,
            \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE,
        );
        $references = [];

        foreach ($matches as $match) {
            $methodOffset = $match[1][1];
            if (!RoutePhpReceiver::isSymfony(substr($text, 0, $methodOffset - 2))) {
                continue;
            }

            $name = $match[3][0];
            $offset = $match[3][1];
            $references[] = new RouteReference(
                $name,
                new Range(
                    $this->positionConverter->toPosition($text, $offset),
                    $this->positionConverter->toPosition($text, $offset + \strlen($name)),
                ),
            );
        }

        return $references;
    }

    public function at(string $text, int $byteOffset): ?RouteReference
    {
        foreach ($this->extract($text) as $reference) {
            $start = $this->positionConverter->toByteOffset($text, $reference->range()->start());
            $end = $this->positionConverter->toByteOffset($text, $reference->range()->end());
            if ($byteOffset >= $start && $byteOffset <= $end) {
                return $reference;
            }
        }

        return null;
    }
}
