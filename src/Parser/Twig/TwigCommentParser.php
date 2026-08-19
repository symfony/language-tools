<?php

namespace Symfony\Lsp\Parser\Twig;

final class TwigCommentParser
{
    public function mask(string $source): string
    {
        $masked = $source;
        $length = \strlen($source);
        $state = 'data';
        $quote = null;
        $escaped = false;
        $brackets = [];

        for ($offset = 0; $offset < $length;) {
            if ('data' === $state) {
                if ('{#' === substr($source, $offset, 2)) {
                    $end = strpos($source, '#}', $offset + 2);
                    if (false === $end) {
                        break;
                    }
                    $end += 2;
                    $this->maskRange($masked, $source, $offset, $end);
                    $offset = $end;
                    continue;
                }
                if ('{%' === substr($source, $offset, 2)) {
                    $state = 'block';
                    $brackets = [];
                    $offset += 2;
                    continue;
                }
                if ('{{' === substr($source, $offset, 2)) {
                    $state = 'variable';
                    $brackets = [];
                    $offset += 2;
                    continue;
                }
                ++$offset;
                continue;
            }

            $character = $source[$offset];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }
                ++$offset;
                continue;
            }

            $closingDelimiter = 'block' === $state ? '%}' : '}}';
            if ([] === $brackets && $closingDelimiter === substr($source, $offset, 2)) {
                $state = 'data';
                $offset += 2;
                continue;
            }
            if ('\'' === $character || '"' === $character) {
                $quote = $character;
                ++$offset;
                continue;
            }
            if ('#' === $character) {
                $end = $offset + strcspn($source, "\r\n", $offset);
                $this->maskRange($masked, $source, $offset, $end);
                $offset = $end;
                continue;
            }
            if ('(' === $character) {
                $brackets[] = ')';
            } elseif ('[' === $character) {
                $brackets[] = ']';
            } elseif ('{' === $character) {
                $brackets[] = '}';
            } elseif ([] !== $brackets && $character === $brackets[array_key_last($brackets)]) {
                array_pop($brackets);
            }
            ++$offset;
        }

        return $masked;
    }

    private function maskRange(string &$masked, string $source, int $start, int $end): void
    {
        for ($offset = $start; $offset < $end; ++$offset) {
            if ("\r" !== $source[$offset] && "\n" !== $source[$offset]) {
                $masked[$offset] = ' ';
            }
        }
    }
}
