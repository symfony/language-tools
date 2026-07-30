<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class PhpRouteDeclarationExtractor
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
            '/#\[\s*(?:\\\\?Symfony\\\\Component\\\\Routing\\\\Attribute\\\\)?Route\s*\((.*?)\)\s*\]/s',
            $text,
            $attributes,
            \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE,
        );
        $declarations = [];

        foreach ($attributes as $attribute) {
            if (!preg_match(
                '/\bname\s*:\s*([\'\"])([^\'\"]+)\1/s',
                $attribute[1][0],
                $name,
                \PREG_OFFSET_CAPTURE,
            )) {
                continue;
            }

            $routeName = $name[2][0];
            $offset = $attribute[1][1] + $name[2][1];
            $declarations[] = new RouteDeclaration(
                $routeName,
                $uri,
                new Range(
                    $this->positionConverter->toPosition($text, $offset),
                    $this->positionConverter->toPosition($text, $offset + \strlen($routeName)),
                ),
            );
        }

        return $declarations;
    }
}
