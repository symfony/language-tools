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
            ) && !preg_match(
                '/^\s*(?:([\'\"])[^\'\"]*\1|null)\s*,\s*([\'\"])([^\'\"]+)\2/s',
                $attribute[1][0],
                $positionalName,
                \PREG_OFFSET_CAPTURE,
            )) {
                continue;
            }

            $nameMatch = isset($name[2]) ? $name[2] : $positionalName[3];
            $declarations[] = $this->declaration(
                $nameMatch[0],
                $uri,
                $text,
                $attribute[1][1] + $nameMatch[1],
            );
        }

        preg_match_all(
            '/\$(\w+)\s*->\s*add\s*\(\s*([\'\"])([^\'\"]+)\2/s',
            $text,
            $calls,
            \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE,
        );
        foreach ($calls as $call) {
            $variable = $call[1][0];
            $beforeCall = substr($text, 0, $call[0][1]);
            if (!preg_match(
                '/(?:RoutingConfigurator\s+\$'.preg_quote($variable, '/').'\b|\$'.preg_quote($variable, '/').'\s*=\s*new\s+(?:\\\\?RouteCollection|[^\s;(]*\\\\RouteCollection)\b)/s',
                $beforeCall,
            )) {
                continue;
            }

            $declarations[] = $this->declaration(
                $call[3][0],
                $uri,
                $text,
                $call[3][1],
            );
        }

        usort(
            $declarations,
            static fn (RouteDeclaration $left, RouteDeclaration $right): int => $left->range()->start()->line() <=> $right->range()->start()->line()
                ?: $left->range()->start()->character() <=> $right->range()->start()->character(),
        );

        return $declarations;
    }

    private function declaration(string $name, string $uri, string $text, int $offset): RouteDeclaration
    {
        return new RouteDeclaration(
            $name,
            $uri,
            new Range(
                $this->positionConverter->toPosition($text, $offset),
                $this->positionConverter->toPosition($text, $offset + \strlen($name)),
            ),
        );
    }
}
