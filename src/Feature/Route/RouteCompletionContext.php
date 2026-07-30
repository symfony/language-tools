<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class RouteCompletionContext
{
    private const METHODS = [
        'generate',
        'generateUrl',
        'redirectToRoute',
    ];

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

    public static function fromPhp(string $text, Position $position, PositionConverter $positionConverter): ?self
    {
        $cursor = $positionConverter->toByteOffset($text, $position);
        $beforeCursor = substr($text, 0, $cursor);
        if (!preg_match(
            '/(?:->|::)('.implode('|', self::METHODS).')\s*\(\s*([\'\"])([^\'\"]*)$/s',
            $beforeCursor,
            $matches,
            \PREG_OFFSET_CAPTURE,
        )) {
            return null;
        }

        $methodOffset = $matches[1][1];
        $operatorLength = 2;
        if (!self::hasSymfonyReceiver(substr($beforeCursor, 0, $methodOffset - $operatorLength))) {
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

    private static function hasSymfonyReceiver(string $beforeMethod): bool
    {
        $receiverSource = rtrim($beforeMethod);

        if (preg_match('/\$this\s*$/', $receiverSource)) {
            return (bool) preg_match('/class\s+\w+\s+extends\s+(?:AbstractController|[^\s{]*\\\\AbstractController)\b/s', $receiverSource);
        }

        if (!preg_match('/\$(\w+)\s*$/', $receiverSource, $receiver)) {
            return false;
        }

        return (bool) preg_match(
            '/(?:RouterInterface|UrlGeneratorInterface)\s+\$'.preg_quote($receiver[1], '/').'\b/s',
            $receiverSource,
        );
    }
}
