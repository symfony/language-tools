<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class ParameterCompletionContext
{
    public function __construct(
        public readonly string $prefix,
        public readonly Range $replacementRange,
        private readonly bool $appendClosingPercent,
    ) {
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

    public static function fromPhpAutowire(PhpAutowireArgument $argument, string $text, Position $position, PositionConverter $positionConverter): ?self
    {
        if ('param' === $argument->name) {
            return new self(
                $argument->value,
                new Range($positionConverter->toPosition($text, $argument->valueStartOffset), $position),
                false,
            );
        }

        if (null !== $argument->name || 0 !== $argument->position || !str_starts_with($argument->value, '%')) {
            return null;
        }

        $name = substr($argument->value, 1);
        if (str_contains($name, '%') || str_starts_with($name, 'env(')) {
            return null;
        }

        return new self(
            $name,
            new Range($positionConverter->toPosition($text, $argument->valueStartOffset + 1), $position),
            '%' !== ($text[$argument->cursorOffset()] ?? null),
        );
    }
}
