<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class ParameterCompletionContext
{
    public function __construct(
        private readonly string $prefix,
        private readonly Range $replacementRange,
        private readonly bool $appendClosingPercent,
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

    public function completionText(string $name): string
    {
        return $name.($this->appendClosingPercent ? '%' : '');
    }

    public static function fromYaml(string $text, Position $position, PositionConverter $positionConverter): ?self
    {
        $cursor = $positionConverter->toByteOffset($text, $position);
        if (!preg_match(
            '/%([^%\'"\s]*)$/',
            substr($text, 0, $cursor),
            $matches,
            \PREG_OFFSET_CAPTURE,
        ) || str_starts_with($matches[1][0], 'env(')) {
            return null;
        }

        return new self(
            $matches[1][0],
            new Range($positionConverter->toPosition($text, $matches[1][1]), $position),
            '%' !== ($text[$cursor] ?? null),
        );
    }

    public static function fromPhp(string $text, Position $position, PositionConverter $positionConverter): ?self
    {
        $cursor = $positionConverter->toByteOffset($text, $position);
        $beforeCursor = substr($text, 0, $cursor);
        if (preg_match(
            '/#\[\s*(?:\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*\\\\)?Autowire\s*\(.*\bparam\s*:\s*([\'\"])([^\'\"]*)$/s',
            $beforeCursor,
            $matches,
            \PREG_OFFSET_CAPTURE,
        )) {
            return new self(
                $matches[2][0],
                new Range($positionConverter->toPosition($text, $matches[2][1]), $position),
                false,
            );
        }

        if (!preg_match(
            '/#\[\s*(?:\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*\\\\)?Autowire\s*\(\s*([\'\"])%([^%\'\"]*)$/s',
            $beforeCursor,
            $matches,
            \PREG_OFFSET_CAPTURE,
        ) || str_starts_with($matches[2][0], 'env(')) {
            return null;
        }

        return new self(
            $matches[2][0],
            new Range($positionConverter->toPosition($text, $matches[2][1]), $position),
            '%' !== ($text[$cursor] ?? null),
        );
    }
}
