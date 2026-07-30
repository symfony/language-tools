<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class YamlRouteDeclarationExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
    ) {
    }

    /**
     * @return list<RouteDeclaration>
     */
    public function extract(string $uri, string $text): array
    {
        preg_match_all(
            '/^(?<quote>[\'\"]?)(?<name>[^\s\'\"#][^:\r\n]*?)\k<quote>\s*:\s*(?:#.*)?$/m',
            $text,
            $entries,
            \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE,
        );
        $declarations = [];

        foreach ($entries as $entry) {
            $entryOffset = $entry[0][1];
            if (!$this->isRouteBlock($text, $entryOffset + \strlen($entry[0][0]))) {
                continue;
            }

            $name = rtrim($entry['name'][0]);
            $offset = $entry['name'][1];
            $declarations[] = new RouteDeclaration(
                $name,
                $uri,
                new Range(
                    $this->positionConverter->toPosition($text, $offset),
                    $this->positionConverter->toPosition($text, $offset + \strlen($name)),
                ),
            );
        }

        return $declarations;
    }

    private function isRouteBlock(string $text, int $blockOffset): bool
    {
        $remaining = substr($text, $blockOffset);
        $nextEntry = preg_match('/^\S[^:\r\n]*\s*:/m', $remaining, $match, \PREG_OFFSET_CAPTURE)
            ? $match[0][1]
            : \strlen($remaining);
        $block = substr($remaining, 0, $nextEntry);

        return (bool) preg_match('/^\s+(?:path|controller|methods|host|schemes|condition|defaults|requirements|options)\s*:/m', $block);
    }
}
