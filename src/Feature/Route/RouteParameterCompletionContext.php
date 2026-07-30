<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class RouteParameterCompletionContext
{
    public function __construct(
        private readonly string $routeName,
        private readonly string $prefix,
        private readonly Range $replacementRange,
    ) {
    }

    public function routeName(): string
    {
        return $this->routeName;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function replacementRange(): Range
    {
        return $this->replacementRange;
    }

    public static function fromPhp(string $text, Position $position, PositionConverter $positionConverter): ?self
    {
        $cursor = $positionConverter->toByteOffset($text, $position);
        $beforeCursor = substr($text, 0, $cursor);
        if (!preg_match(
            '/(?:->|::)(?:generate|generateUrl|redirectToRoute)\s*\(\s*([\'\"])([^\'\"]+)\1\s*,\s*\[[^\]]*?([\'\"])([^\'\"]*)$/s',
            $beforeCursor,
            $matches,
            \PREG_OFFSET_CAPTURE,
        )) {
            return null;
        }

        $prefix = $matches[4][0];
        $prefixOffset = $matches[4][1];

        return new self(
            $matches[2][0],
            $prefix,
            new Range($positionConverter->toPosition($text, $prefixOffset), $position),
        );
    }
}
