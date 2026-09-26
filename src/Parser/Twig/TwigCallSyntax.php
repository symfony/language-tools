<?php

namespace Symfony\Lsp\Parser\Twig;

final class TwigCallSyntax
{
    public static function isMethodCall(string $text, int $nameOffset): bool
    {
        return '.' === self::previousCharacter($text, $nameOffset);
    }

    public static function isFunctionCall(string $text, int $nameOffset): bool
    {
        return !\in_array(self::previousCharacter($text, $nameOffset), ['.', '|'], true);
    }

    private static function previousCharacter(string $text, int $offset): ?string
    {
        $before = rtrim(substr($text, 0, $offset));

        return '' === $before ? null : $before[-1];
    }
}
