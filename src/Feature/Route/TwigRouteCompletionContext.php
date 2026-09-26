<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Twig\TwigCallSyntax;

final class TwigRouteCompletionContext
{
    public function __construct(
        public readonly string $prefix,
        public readonly Range $replacementRange,
    ) {
    }

    public static function fromTwig(string $text, Position $position, PositionConverter $positionConverter): ?self
    {
        $cursor = $positionConverter->toByteOffset($text, $position);
        $beforeCursor = substr($text, 0, $cursor);
        if (!preg_match(
            '/\b(path|url)\s*\(\s*(?:name\s*[:=]\s*)?([\'\"])([^\'\"]*)$/s',
            $beforeCursor,
            $matches,
            \PREG_OFFSET_CAPTURE,
        ) || !TwigCallSyntax::isFunctionCall($beforeCursor, $matches[1][1])) {
            return null;
        }

        $prefix = $matches[3][0];
        $offset = $matches[3][1];

        return new self(
            $prefix,
            new Range($positionConverter->toPosition($text, $offset), $position),
        );
    }
}
