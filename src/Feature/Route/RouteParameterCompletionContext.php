<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class RouteParameterCompletionContext
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

    /** @param callable(string): bool $isSymfonyReceiver */
    public static function fromPhp(string $text, Position $position, PositionConverter $positionConverter, callable $isSymfonyReceiver): ?self
    {
        $cursor = $positionConverter->toByteOffset($text, $position);
        $beforeCursor = substr($text, 0, $cursor);
        if (!preg_match(
            '/(?:->|::)(generate|generateUrl|redirectToRoute)\s*\(\s*([\'\"])([^\'\"]+)\2\s*,\s*\[([^\]]*?)([\'\"])([^\'\"]*)$/s',
            $beforeCursor,
            $matches,
            \PREG_OFFSET_CAPTURE,
        )) {
            return null;
        }

        $methodOffset = $matches[1][1];
        if (!$isSymfonyReceiver(substr($beforeCursor, 0, $methodOffset - 2))) {
            return null;
        }

        $prefix = $matches[6][0];
        $prefixOffset = $matches[6][1];
        preg_match_all('/([\'\"])([^\'\"]+)\1\s*=>/', $matches[4][0], $keys);

        return new self(
            $matches[3][0],
            $prefix,
            new Range($positionConverter->toPosition($text, $prefixOffset), $position),
            array_values(array_unique($keys[2])),
        );
    }
}
