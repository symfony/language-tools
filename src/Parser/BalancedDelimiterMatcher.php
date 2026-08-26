<?php

namespace Symfony\Lsp\Parser;

final class BalancedDelimiterMatcher
{
    public function matching(string $text, int $open, string $opening, string $closing): ?int
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        for ($index = $open, $length = \strlen($text); $index < $length; ++$index) {
            $character = $text[$index];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }
                continue;
            }
            if ('\'' === $character || '"' === $character) {
                $quote = $character;
            } elseif ($opening === $character) {
                ++$depth;
            } elseif ($closing === $character && 0 === --$depth) {
                return $index;
            }
        }

        return null;
    }
}
