<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;

/**
 * Builds the LSP request parameters a feature provider expects, placing the
 * cursor relative to a needle instead of an absolute offset.
 */
final class LspRequests
{
    /** @return array{textDocument: array{uri: string}} */
    public static function document(string $uri): array
    {
        return ['textDocument' => ['uri' => $uri]];
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    public static function position(string $uri, Position $position): array
    {
        return ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line, 'character' => $position->character]];
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    public static function offset(string $uri, string $text, int $offset): array
    {
        return self::position($uri, (new PositionConverter())->toPosition($text, $offset));
    }

    /**
     * The cursor sits on the first byte of the needle.
     *
     * @return array{textDocument: array{uri: string}, position: array{line: int, character: int}}
     */
    public static function at(string $uri, string $text, string $needle): array
    {
        return self::offset($uri, $text, self::needle($text, $needle));
    }

    /**
     * The cursor sits in the middle of the needle, taken at the offset it starts
     * at when that is not its first occurrence.
     *
     * @return array{textDocument: array{uri: string}, position: array{line: int, character: int}}
     */
    public static function inside(string $uri, string $text, string $needle, ?int $needleOffset = null): array
    {
        return self::offset($uri, $text, ($needleOffset ?? self::needle($text, $needle)) + intdiv(\strlen($needle), 2));
    }

    /**
     * The cursor sits right after the needle, where it lands once it was typed.
     *
     * @return array{textDocument: array{uri: string}, position: array{line: int, character: int}}
     */
    public static function after(string $uri, string $text, string $needle): array
    {
        return self::offset($uri, $text, self::needle($text, $needle) + \strlen($needle));
    }

    private static function needle(string $text, string $needle): int
    {
        $offset = strpos($text, $needle);
        if (false === $offset) {
            throw new \InvalidArgumentException(\sprintf('The needle "%s" is not part of the document.', $needle));
        }

        return $offset;
    }
}
