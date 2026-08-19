<?php

namespace Symfony\Lsp\Parser\Twig;

final class TwigCommentParser
{
    public function mask(string $source): string
    {
        $masked = $source;
        $length = \strlen($source);
        $state = 'data';
        $brackets = [];

        for ($offset = 0; $offset < $length;) {
            if ('data' === $state) {
                if ('{#' === substr($source, $offset, 2)) {
                    $end = strpos($source, '#}', $offset + 2);
                    if (false === $end) {
                        $this->maskRange($masked, $source, $offset + 2, $length);
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
            $closingDelimiter = 'block' === $state ? '%}' : '}}';
            if ([] === $brackets && $closingDelimiter === substr($source, $offset, 2)) {
                $state = 'data';
                $offset += 2;
                continue;
            }
            if ('\'' === $character || '"' === $character) {
                $offset = $this->stringEnd($masked, $source, $offset, $length);
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

    private function stringEnd(string &$masked, string $source, int $offset, int $end): int
    {
        $quote = $source[$offset++];
        while ($offset < $end) {
            if ('\\' === $source[$offset]) {
                $offset += 2;
                continue;
            }
            if ($quote === $source[$offset]) {
                return $offset + 1;
            }
            if ('"' === $quote && '#{' === substr($source, $offset, 2)) {
                $offset = $this->interpolationEnd($masked, $source, $offset + 2, $end);
                continue;
            }
            ++$offset;
        }

        return $end;
    }

    private function interpolationEnd(string &$masked, string $source, int $offset, int $end): int
    {
        $brackets = ['}'];
        while ($offset < $end) {
            $character = $source[$offset];
            if ('\'' === $character || '"' === $character) {
                $offset = $this->stringEnd($masked, $source, $offset, $end);
                continue;
            }
            if ('#' === $character) {
                $lineEnd = $offset + strcspn($source, "\r\n", $offset);
                $this->maskRange($masked, $source, $offset, $lineEnd);
                $offset = $lineEnd;
                continue;
            }
            if ('(' === $character) {
                $brackets[] = ')';
            } elseif ('[' === $character) {
                $brackets[] = ']';
            } elseif ('{' === $character) {
                $brackets[] = '}';
            } elseif ($character === $brackets[array_key_last($brackets)]) {
                array_pop($brackets);
                ++$offset;
                if ([] === $brackets) {
                    return $offset;
                }
                continue;
            }
            ++$offset;
        }

        return $end;
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
