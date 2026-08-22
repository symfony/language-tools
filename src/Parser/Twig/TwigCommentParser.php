<?php

namespace Symfony\Lsp\Parser\Twig;

/**
 * Blanks Twig comments and verbatim content while preserving byte offsets
 * and UTF-16 positions.
 *
 * Only ASCII bytes are replaced with spaces: multibyte sequences keep their
 * byte length and UTF-16 unit count, so positions measured on the masked
 * text always match the original document.
 */
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
                    if (null !== $verbatim = $this->verbatim($source, $offset, $length)) {
                        [$contentStart, $contentEnd, $offset] = $verbatim;
                        $this->maskRange($masked, $source, $contentStart, $contentEnd);
                        continue;
                    }
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

    /** @return array{int, int, int}|null */
    private function verbatim(string $source, int $offset, int $end): ?array
    {
        if (!preg_match('/\{%[-~]?\s*verbatim\s*[-~]?%\}/A', $source, $opening, \PREG_OFFSET_CAPTURE, $offset)) {
            return null;
        }
        $contentStart = $offset + \strlen($opening[0][0]);
        if (!preg_match('/\{%[-~]?\s*endverbatim\s*[-~]?%\}/', $source, $closing, \PREG_OFFSET_CAPTURE, $contentStart)) {
            return [$contentStart, $end, $end];
        }
        $contentEnd = $closing[0][1];

        return [$contentStart, $contentEnd, $contentEnd + \strlen($closing[0][0])];
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
            $byte = $source[$offset];
            if ("\r" !== $byte && "\n" !== $byte && \ord($byte) < 0x80) {
                $masked[$offset] = ' ';
            }
        }
    }
}
