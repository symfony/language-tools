<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class ServiceCompletionContext
{
    public function __construct(
        public readonly string $prefix,
        public readonly Range $replacementRange,
    ) {
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

    public static function fromPhpAutowire(PhpAutowireArgument $argument, string $text, Position $position, PositionConverter $positionConverter): ?self
    {
        if ('service' !== $argument->name) {
            return null;
        }

        $optional = str_starts_with($argument->value, '?');

        return new self(
            $optional ? substr($argument->value, 1) : $argument->value,
            new Range(
                $positionConverter->toPosition($text, $argument->valueStartOffset + ($optional ? 1 : 0)),
                $position,
            ),
        );
    }
}
