<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Twig\TwigCallSyntax;

final class TwigRouteParameterCompletionContext
{
    /**
     * @param list<string> $existingParameters
     */
    public function __construct(
        public readonly string $routeName,
        public readonly string $prefix,
        public readonly Range $replacementRange,
        public readonly array $existingParameters,
    ) {
    }

    public static function fromTwig(string $text, Position $position, PositionConverter $positionConverter): ?self
    {
        $cursor = $positionConverter->toByteOffset($text, $position);
        $beforeCursor = substr($text, 0, $cursor);
        if (!preg_match(
            '/\b(path|url)\s*\(\s*(?:name\s*[:=]\s*)?([\'\"])([^\'\"]+)\2\s*,\s*(?:parameters\s*[:=]\s*)?\{([^}]*?)([\'\"])([^\'\"]*)$/s',
            $beforeCursor,
            $matches,
            \PREG_OFFSET_CAPTURE,
        ) || !TwigCallSyntax::isFunctionCall($beforeCursor, $matches[1][1])
            || !\in_array(substr(rtrim($matches[4][0]), -1), ['', ','], true)
        ) {
            return null;
        }

        $prefix = $matches[6][0];
        $prefixOffset = $matches[6][1];
        preg_match_all('/([\'\"])([^\'\"]+)\1\s*:/', $matches[4][0], $keys);

        return new self(
            $matches[3][0],
            $prefix,
            new Range($positionConverter->toPosition($text, $prefixOffset), $position),
            array_values(array_unique($keys[2])),
        );
    }
}
