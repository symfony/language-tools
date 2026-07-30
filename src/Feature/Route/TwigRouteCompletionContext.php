<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class TwigRouteCompletionContext
{
    public function __construct(
        private readonly string $prefix,
        private readonly Range $replacementRange,
    ) {
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function replacementRange(): Range
    {
        return $this->replacementRange;
    }

    public static function fromTwig(string $text, Position $position, PositionConverter $positionConverter): ?self
    {
        $cursor = $positionConverter->toByteOffset($text, $position);
        $beforeCursor = substr($text, 0, $cursor);
        if (!preg_match(
            '/\b(?:path|url)\s*\(\s*([\'\"])([^\'\"]*)$/s',
            $beforeCursor,
            $matches,
            \PREG_OFFSET_CAPTURE,
        )) {
            return null;
        }

        $prefix = $matches[2][0];
        $offset = $matches[2][1];

        return new self(
            $prefix,
            new Range($positionConverter->toPosition($text, $offset), $position),
        );
    }
}
