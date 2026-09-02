<?php

namespace Symfony\Lsp\Feature\Stimulus;

final class JavaScriptSourceAnalyzer
{
    public function mask(string $text): string
    {
        return $this->scan($text)[0];
    }

    /** @return list<array{string, int}> */
    public function quotedStrings(string $text): array
    {
        return $this->scan($text)[1];
    }

    /** @return array{string, list<array{string, int}>} */
    private function scan(string $text): array
    {
        $masked = $text;
        $strings = [];
        $length = \strlen($text);
        $state = 'code';
        $quote = null;
        $stringOffset = 0;

        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $text[$offset];
            if ('code' === $state) {
                if ('//' === substr($text, $offset, 2)) {
                    $this->maskByte($masked, $text, $offset);
                    $this->maskByte($masked, $text, ++$offset);
                    $state = 'line_comment';
                } elseif ('/*' === substr($text, $offset, 2)) {
                    $this->maskByte($masked, $text, $offset);
                    $this->maskByte($masked, $text, ++$offset);
                    $state = 'block_comment';
                } elseif ('/' === $character && null !== $end = $this->regularExpressionEnd($text, $offset)) {
                    while ($offset <= $end) {
                        $this->maskByte($masked, $text, $offset++);
                    }
                    --$offset;
                } elseif ('\'' === $character || '"' === $character || '`' === $character) {
                    $quote = $character;
                    $stringOffset = $offset + 1;
                    $this->maskByte($masked, $text, $offset);
                    $state = 'string';
                }
                continue;
            }

            if ('line_comment' === $state) {
                if ("\r" === $character || "\n" === $character) {
                    $state = 'code';
                } else {
                    $this->maskByte($masked, $text, $offset);
                }
                continue;
            }

            $this->maskByte($masked, $text, $offset);
            if ('block_comment' === $state) {
                if ('*/' === substr($text, $offset, 2)) {
                    $this->maskByte($masked, $text, ++$offset);
                    $state = 'code';
                }
                continue;
            }

            if ('\\' === $character) {
                if (++$offset < $length) {
                    $this->maskByte($masked, $text, $offset);
                }
            } elseif ($character === $quote) {
                if ('`' !== $quote) {
                    $strings[] = [substr($text, $stringOffset, $offset - $stringOffset), $stringOffset];
                }
                $quote = null;
                $state = 'code';
            }
        }

        return [$masked, $strings];
    }

    private function regularExpressionEnd(string $text, int $offset): ?int
    {
        $before = rtrim(substr($text, 0, $offset));
        if ('' !== $before) {
            $previous = $before[\strlen($before) - 1];
            if (!str_contains('([{:;,=?&|%^~<', $previous)
                && !str_ends_with($before, '=>')
                && 1 !== preg_match('/(?:^|[^A-Za-z0-9_$])(?:await|case|delete|do|else|in|instanceof|new|of|return|throw|typeof|void|yield)$/', $before)) {
                return null;
            }
        }

        $characterClass = false;
        for ($end = $offset + 1, $length = \strlen($text); $end < $length; ++$end) {
            $character = $text[$end];
            if ("\r" === $character || "\n" === $character) {
                return null;
            }
            if ('\\' === $character) {
                ++$end;
                continue;
            }
            if ('[' === $character) {
                $characterClass = true;
            } elseif (']' === $character) {
                $characterClass = false;
            } elseif ('/' === $character && !$characterClass) {
                while (isset($text[$end + 1]) && str_contains('dgimsuvy', $text[$end + 1])) {
                    ++$end;
                }

                return $end;
            }
        }

        return null;
    }

    private function maskByte(string &$masked, string $text, int $offset): void
    {
        if ("\r" !== $text[$offset] && "\n" !== $text[$offset] && \ord($text[$offset]) < 0x80) {
            $masked[$offset] = ' ';
        }
    }
}
