<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class RouteCompletionContext
{
    public function __construct(
        public readonly string $prefix,
        public readonly Range $replacementRange,
    ) {
    }

    public static function fromPhp(string $text, Position $position, PositionConverter $positionConverter): ?self
    {
        $cursor = $positionConverter->toByteOffset($text, $position);
        $beforeCursor = substr($text, 0, $cursor);
        if (!preg_match(
            '/(?:->|::)('.implode('|', RoutePhpMethods::ALL).')\s*\(\s*([\'\"])([^\'\"]*)$/s',
            $beforeCursor,
            $matches,
            \PREG_OFFSET_CAPTURE,
        )) {
            return null;
        }

        $prefix = $matches[3][0];
        $prefixOffset = $matches[3][1];

        return new self(
            $prefix,
            new Range(
                $positionConverter->toPosition($text, $prefixOffset),
                $position,
            ),
        );
    }
}
