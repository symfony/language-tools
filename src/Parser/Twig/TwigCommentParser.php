<?php

namespace Symfony\Lsp\Parser\Twig;

use Symfony\Lsp\Parser\CommentParseResult;
use Symfony\Lsp\Parser\CommentParserInterface;
use Symfony\Lsp\Parser\SourceComment;

/**
 * Blanks Twig comments and verbatim content while preserving byte offsets
 * and UTF-16 positions.
 *
 * Only ASCII bytes are replaced with spaces: multibyte sequences keep their
 * byte length and UTF-16 unit count, so positions measured on the masked
 * text always match the original document.
 */
final class TwigCommentParser implements CommentParserInterface
{
    private ?string $lastSource = null;
    private ?CommentParseResult $lastResult = null;

    public function parse(string $source): CommentParseResult
    {
        if ($source === $this->lastSource && null !== $this->lastResult) {
            return $this->lastResult;
        }

        $result = $this->scan($source);
        $this->lastSource = $source;

        return $this->lastResult = $result;
    }

    public function mask(string $source): string
    {
        return $this->parse($source)->masked;
    }

    public function comments(string $source): array
    {
        return $this->parse($source)->comments;
    }

    private function scan(string $source): CommentParseResult
    {
        $masked = $source;
        $comments = [];
        $length = \strlen($source);
        $state = 'data';
        $brackets = [];

        for ($offset = 0; $offset < $length;) {
            if ('data' === $state) {
                if ('{#' === substr($source, $offset, 2)) {
                    $closing = strpos($source, '#}', $offset + 2);
                    $end = false === $closing ? $length : $closing + 2;
                    $contentEnd = false === $closing ? $length : $closing;
                    $this->comment($comments, $source, $offset, $end, $offset + 2, $contentEnd);
                    if (false === $closing) {
                        $this->maskRange($masked, $source, $offset + 2, $end);
                        break;
                    }
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
                $offset = $this->stringEnd($masked, $comments, $source, $offset, $length);
                continue;
            }
            if ('#' === $character) {
                $end = $offset + strcspn($source, "\r\n", $offset);
                $this->comment($comments, $source, $offset, $end, $offset + 1, $end);
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

        return new CommentParseResult($masked, $comments);
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

    /** @param list<SourceComment> $comments */
    private function stringEnd(string &$masked, array &$comments, string $source, int $offset, int $end): int
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
                $offset = $this->interpolationEnd($masked, $comments, $source, $offset + 2, $end);
                continue;
            }
            ++$offset;
        }

        return $end;
    }

    /** @param list<SourceComment> $comments */
    private function interpolationEnd(string &$masked, array &$comments, string $source, int $offset, int $end): int
    {
        $brackets = ['}'];
        while ($offset < $end) {
            $character = $source[$offset];
            if ('\'' === $character || '"' === $character) {
                $offset = $this->stringEnd($masked, $comments, $source, $offset, $end);
                continue;
            }
            if ('#' === $character) {
                $lineEnd = $offset + strcspn($source, "\r\n", $offset);
                $this->comment($comments, $source, $offset, $lineEnd, $offset + 1, $lineEnd);
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

    /** @param list<SourceComment> $comments */
    private function comment(array &$comments, string $source, int $start, int $end, int $contentStart, int $contentEnd): void
    {
        $comments[] = new SourceComment(
            $start,
            $end,
            $contentStart,
            $contentEnd,
            substr($source, $contentStart, $contentEnd - $contentStart),
        );
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
