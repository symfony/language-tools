<?php

namespace Symfony\Lsp\Parser;

/** With $twig, the #{...} interpolations of double-quoted strings are scanned as nested code. */
final class DelimiterScanner
{
    private const PAIRS = ['(' => ')', '[' => ']', '{' => '}', '#{' => '}'];

    /**
     * Splits $text on the $separator bytes that sit outside strings and nested delimiters.
     *
     * @return list<DelimiterSegment>
     */
    public static function split(string $text, string $separator = ',', int $baseOffset = 0, bool $phpComments = false, bool $twig = false): array
    {
        $segments = [];
        $start = 0;
        $scan = self::scan($text, 0, \strlen($text), $separator, null, $phpComments, false, $twig);
        foreach ($scan['separators'] as $offset) {
            $segments[] = new DelimiterSegment(substr($text, $start, $offset - $start), $baseOffset + $start);
            $start = $offset + 1;
        }
        $segments[] = new DelimiterSegment(substr($text, $start), $baseOffset + $start);

        return $segments;
    }

    /** Offset of the first $terminator outside strings and nested delimiters. */
    public static function terminator(string $text, int $start, string $terminator, ?int $end = null, bool $twig = false): ?int
    {
        return self::scan($text, $start, $end ?? \strlen($text), null, $terminator, false, false, $twig)['stop'];
    }

    /** Offset of the delimiter closing the one opened at $openOffset. */
    public static function close(string $text, int $openOffset): ?int
    {
        $closing = self::PAIRS[$text[$openOffset] ?? ''] ?? null;

        return null === $closing ? null : self::terminator($text, $openOffset + 1, $closing);
    }

    /** The string and the delimiters left open at the end of the scanned range. */
    public static function state(string $text, int $start = 0, ?int $end = null, bool $twig = false): DelimiterState
    {
        $scan = self::scan($text, $start, $end ?? \strlen($text), null, null, false, false, $twig);

        return new DelimiterState($scan['open'], null === $scan['quote'] ? null : new DelimiterString($scan['quote'], $scan['content']));
    }

    /** Replaces string contents with spaces, keeping every other byte and every offset. */
    public static function maskStrings(string $text, bool $twig = false): string
    {
        $masked = $text;
        foreach (self::scan($text, 0, \strlen($text), null, null, false, true, $twig)['strings'] as [$start, $end]) {
            for ($offset = $start; $offset < $end; ++$offset) {
                if ("\n" !== $text[$offset]) {
                    $masked[$offset] = ' ';
                }
            }
        }

        return $masked;
    }

    /**
     * @return array{
     *     separators: list<int>,
     *     stop: int|null,
     *     open: list<DelimiterOpening>,
     *     quote: string|null,
     *     content: int,
     *     strings: list<array{int, int}>,
     * }
     */
    private static function scan(string $text, int $start, int $end, ?string $separator, ?string $terminator, bool $phpComments, bool $collectStrings, bool $twig): array
    {
        $separators = [];
        $strings = [];
        $open = [];
        $quote = null;
        $content = 0;
        $stop = null;
        $escaped = false;
        $lineComment = false;
        $blockComment = false;
        $terminatorLength = null === $terminator ? 0 : \strlen($terminator);
        for ($offset = $start; $offset < $end; ++$offset) {
            $character = $text[$offset];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    if ($collectStrings) {
                        $strings[] = [$content, $offset];
                    }
                    $quote = null;
                } elseif ($twig && '"' === $quote && '#' === $character && '{' === ($text[$offset + 1] ?? null)) {
                    if ($collectStrings) {
                        $strings[] = [$content, $offset];
                    }
                    $quote = null;
                    $open[] = new DelimiterOpening('#{', $offset);
                    ++$offset;
                }
                continue;
            }
            if ($lineComment) {
                $lineComment = "\n" !== $character && "\r" !== $character;
                continue;
            }
            if ($blockComment) {
                if ('*' === $character && '/' === ($text[$offset + 1] ?? null)) {
                    $blockComment = false;
                    ++$offset;
                }
                continue;
            }
            if ($phpComments && ('/' === $character || '#' === $character)) {
                $next = $text[$offset + 1] ?? null;
                if ('#' === $character) {
                    $lineComment = '[' !== $next;
                    continue;
                }
                if ('/' === $next || '*' === $next) {
                    $lineComment = '/' === $next;
                    $blockComment = '*' === $next;
                    ++$offset;
                    continue;
                }
            }
            if ("'" === $character || '"' === $character) {
                $quote = $character;
                $content = $offset + 1;
                continue;
            }
            if ([] === $open) {
                if (null !== $terminator
                    && $character === $terminator[0]
                    && 0 === substr_compare($text, $terminator, $offset, $terminatorLength)
                ) {
                    $stop = $offset;
                    break;
                }
                if ($separator === $character) {
                    $separators[] = $offset;
                    continue;
                }
            }
            if (isset(self::PAIRS[$character])) {
                $open[] = new DelimiterOpening($character, $offset);
            } elseif ([] !== $open && $character === self::PAIRS[$open[array_key_last($open)]->delimiter]) {
                if ('#{' === array_pop($open)->delimiter) {
                    $quote = '"';
                    $content = $offset + 1;
                }
            }
        }
        if (null !== $quote && $collectStrings) {
            $strings[] = [$content, $end];
        }

        return [
            'separators' => $separators,
            'stop' => $stop,
            'open' => $open,
            'quote' => $quote,
            'content' => $content,
            'strings' => $strings,
        ];
    }
}
