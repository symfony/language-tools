<?php

namespace Symfony\Lsp\Parser\Twig;

final class TwigDirectiveLocator
{
    public function insideDirective(string $text, int $offset): bool
    {
        $close = null;
        $quote = null;
        $escaped = false;
        $brackets = [];
        for ($cursor = 0; $cursor < $offset; ++$cursor) {
            $character = $text[$cursor];
            $pair = substr($text, $cursor, 2);
            if (null === $close) {
                if ('{{' === $pair) {
                    $close = '}}';
                    $brackets = [];
                    ++$cursor;
                } elseif ('{%' === $pair) {
                    $close = '%}';
                    $brackets = [];
                    ++$cursor;
                }
                continue;
            }
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
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
            } elseif (\in_array($character, ['(', '[', '{'], true)) {
                $brackets[] = ['(' => ')', '[' => ']', '{' => '}'][$character];
            } elseif ([] !== $brackets && $character === $brackets[array_key_last($brackets)]) {
                array_pop($brackets);
            } elseif ([] === $brackets && $close === $pair) {
                $close = null;
                ++$cursor;
            }
        }

        return null !== $close;
    }
}
