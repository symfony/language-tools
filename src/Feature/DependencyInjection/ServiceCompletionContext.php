<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class ServiceCompletionContext
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

    public static function fromYaml(string $text, Position $position, PositionConverter $positionConverter): ?self
    {
        $cursor = $positionConverter->toByteOffset($text, $position);
        if (!preg_match(
            '/(?:^|[\s:\'",\[\{\-])@\??([^@\'"\s,\]\}]*)$/',
            substr($text, 0, $cursor),
            $matches,
            \PREG_OFFSET_CAPTURE,
        )) {
            return null;
        }

        $prefix = $matches[1][0];
        $offset = $matches[1][1];

        return new self(
            $prefix,
            new Range($positionConverter->toPosition($text, $offset), $position),
        );
    }

    public static function fromPhp(string $text, Position $position, PositionConverter $positionConverter): ?self
    {
        $cursor = $positionConverter->toByteOffset($text, $position);
        if (!preg_match(
            '/#\[\s*(?:\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*\\\\)?Autowire\s*\(.*\bservice\s*:\s*([\'\"])(\??[^\'\"]*)$/s',
            substr($text, 0, $cursor),
            $matches,
            \PREG_OFFSET_CAPTURE,
        )) {
            return null;
        }

        $prefix = ltrim($matches[2][0], '?');
        $offset = $matches[2][1] + (str_starts_with($matches[2][0], '?') ? 1 : 0);

        return new self(
            $prefix,
            new Range($positionConverter->toPosition($text, $offset), $position),
        );
    }
}
