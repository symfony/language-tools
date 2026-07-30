<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class TwigRouteReferenceExtractor
{
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
            '/\b(?:path|url)\s*\(\s*([\'\"])([^\'\"]+)\1/s',
            $text,
            $matches,
            \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE,
        );
        $references = [];

        foreach ($matches as $match) {
            $name = $match[2][0];
            $offset = $match[2][1];
            $references[] = new RouteReference(
                $name,
                new Range(
                    $this->positionConverter->toPosition($text, $offset),
                    $this->positionConverter->toPosition($text, $offset + \strlen($name)),
                ),
                $this->providedParameters(substr($text, $match[0][1] + \strlen($match[0][0]))),
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

    /**
     * @return list<string>|null
     */
    private function providedParameters(string $afterRouteName): ?array
    {
        if (preg_match('/^\s*\)/', $afterRouteName)) {
            return [];
        }

        if (!preg_match('/^\s*,\s*\{([^{}]*)\}\s*[,)]/s', $afterRouteName, $parameters)) {
            return null;
        }

        preg_match_all('/([\'\"])([^\'\"]+)\1\s*:/', $parameters[1], $keys);

        return array_values(array_unique($keys[2]));
    }
}
