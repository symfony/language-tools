<?php

namespace Symfony\Lsp\Parser\Twig;

final class TwigArgumentParser
{
    /** @return list<TwigArgument> */
    public function parse(string $text, int $baseOffset = 0): array
    {
        $arguments = [];
        $start = 0;
        $stack = [];
        $quote = null;
        $escaped = false;
        for ($offset = 0, $length = \strlen($text); $offset < $length; ++$offset) {
            $character = $text[$offset];
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
                $stack[] = ['(' => ')', '[' => ']', '{' => '}'][$character];
            } elseif ([] !== $stack && $character === $stack[array_key_last($stack)]) {
                array_pop($stack);
            } elseif (',' === $character && [] === $stack) {
                $arguments[] = $this->argument(substr($text, $start, $offset - $start), $baseOffset + $start);
                $start = $offset + 1;
            }
        }
        $arguments[] = $this->argument(substr($text, $start), $baseOffset + $start);

        return $arguments;
    }

    private function argument(string $text, int $offset): TwigArgument
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
