<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class TwigRouteParameterCompletionContext
{
    /**
     * @param list<string> $existingParameters
     */
    public function __construct(
        private readonly string $routeName,
        private readonly string $prefix,
        private readonly Range $replacementRange,
        private readonly array $existingParameters,
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

    /**
     * @return list<string>
     */
    public function existingParameters(): array
    {
        return $this->existingParameters;
    }

    public static function fromTwig(string $text, Position $position, PositionConverter $positionConverter): ?self
    {
        $cursor = $positionConverter->toByteOffset($text, $position);
        $beforeCursor = substr($text, 0, $cursor);
        if (!preg_match(
            '/\b(?:path|url)\s*\(\s*([\'\"])([^\'\"]+)\1\s*,\s*\{([^}]*?)([\'\"])([^\'\"]*)$/s',
            $beforeCursor,
            $matches,
            \PREG_OFFSET_CAPTURE,
        )) {
            return null;
        }

        $prefix = $matches[5][0];
        $prefixOffset = $matches[5][1];
        preg_match_all('/([\'\"])([^\'\"]+)\1\s*:/', $matches[3][0], $keys);

        return new self(
            $matches[2][0],
            $prefix,
            new Range($positionConverter->toPosition($text, $prefixOffset), $position),
            array_values(array_unique($keys[2])),
        );
    }
}
