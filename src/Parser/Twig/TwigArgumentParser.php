<?php

namespace Symfony\Lsp\Parser\Twig;

use Symfony\Lsp\Parser\DelimiterScanner;
use Symfony\Lsp\Parser\DelimiterSegment;

final class TwigArgumentParser
{
    /** @return list<TwigArgument> */
    public static function parse(string $text, int $baseOffset = 0): array
    {
        return array_map(
            static fn (DelimiterSegment $segment): TwigArgument => self::argument($segment->text, $segment->offset),
            DelimiterScanner::split($text, ',', $baseOffset),
        );
    }

    private static function argument(string $text, int $offset): TwigArgument
    {
        if (1 === preg_match('/^[\s\x80-\xff]*([A-Za-z_][A-Za-z0-9_]*)\s*[:=](?![=>])\s*/', $text, $match, \PREG_OFFSET_CAPTURE)) {
            return new TwigArgument(
                $text,
                $offset,
                $offset + \strlen($match[0][0]),
                $match[1][0],
                $offset + $match[1][1],
            );
        }

        return new TwigArgument($text, $offset, $offset + strspn($text, " \t\n\r\0\x0B\f"));
    }
}
