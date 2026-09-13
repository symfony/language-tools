<?php

namespace Symfony\Lsp\Parser\JavaScript;

final class JavaScriptTokenizer
{
    private const WORD_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$';
    private const REGULAR_EXPRESSION_PUNCTUATORS = ['(', '[', '{', ':', ';', ',', '=', '?', '&', '|', '%', '^', '~', '<'];
    private const REGULAR_EXPRESSION_KEYWORDS = ['await', 'case', 'delete', 'do', 'else', 'in', 'instanceof', 'new', 'of', 'return', 'throw', 'typeof', 'void', 'yield'];

    public function tokenize(string $source): JavaScriptTokens
    {
        $tokens = [];
        $comments = [];
        $length = \strlen($source);
        $interpolations = [];
        $startsLine = true;
        $quote = null;
        $contentOffset = 0;
        $contentStartsLine = true;

        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $source[$offset];

            if (null !== $quote) {
                if ('\\' === $character) {
                    ++$offset;
                } elseif ('`' === $quote && '$' === $character && '{' === ($source[$offset + 1] ?? '')) {
                    ++$offset;
                    $interpolations[] = 0;
                    $quote = null;
                } elseif ($character === $quote) {
                    $tokens[] = '`' === $quote
                        ? new JavaScriptToken(JavaScriptTokenKind::Template, '', $contentOffset, $contentStartsLine)
                        : new JavaScriptToken(JavaScriptTokenKind::String, substr($source, $contentOffset, $offset - $contentOffset), $contentOffset, $contentStartsLine);
                    $quote = null;
                }
                continue;
            }

            if ("\r" === $character || "\n" === $character) {
                $startsLine = true;
                continue;
            }
            if (' ' === $character || "\t" === $character || "\v" === $character || "\f" === $character) {
                continue;
            }

            if ('}' === $character && [] !== $interpolations && 0 === $interpolations[array_key_last($interpolations)]) {
                array_pop($interpolations);
                $quote = '`';
                $contentOffset = $offset + 1;
                $contentStartsLine = $startsLine;
                $startsLine = false;
                continue;
            }

            if ('/' === $character) {
                if (null !== $end = $this->commentEnd($source, $offset, $length, $startsLine, $comments)) {
                    $startsLine = false;
                    $offset = $end;
                    continue;
                }
                if ($this->startsRegularExpression($tokens) && null !== $end = $this->regularExpressionEnd($source, $offset)) {
                    $tokens[] = new JavaScriptToken(JavaScriptTokenKind::RegularExpression, substr($source, $offset, $end - $offset + 1), $offset, $startsLine);
                    $startsLine = false;
                    $offset = $end;
                    continue;
                }
            }

            if ('\'' === $character || '"' === $character || '`' === $character) {
                $quote = $character;
                $contentOffset = $offset + 1;
                $contentStartsLine = $startsLine;
                $startsLine = false;
                continue;
            }

            if (0 !== $wordLength = strspn($source, self::WORD_CHARACTERS, $offset)) {
                $word = substr($source, $offset, $wordLength);
                $kind = ctype_digit($word[0]) ? JavaScriptTokenKind::Number : JavaScriptTokenKind::Identifier;
                $tokens[] = new JavaScriptToken($kind, $word, $offset, $startsLine);
                $startsLine = false;
                $offset += $wordLength - 1;
                continue;
            }

            if ([] !== $interpolations && '{' === $character) {
                ++$interpolations[array_key_last($interpolations)];
            } elseif ([] !== $interpolations && '}' === $character) {
                --$interpolations[array_key_last($interpolations)];
            }
            $tokens[] = new JavaScriptToken(JavaScriptTokenKind::Punctuator, $character, $offset, $startsLine);
            $startsLine = false;
        }

        return new JavaScriptTokens($tokens, $comments);
    }

    /** @param list<JavaScriptToken> $comments */
    private function commentEnd(string $source, int $offset, int $length, bool $startsLine, array &$comments): ?int
    {
        $next = $source[$offset + 1] ?? '';
        if ('/' === $next) {
            $end = $offset + strcspn($source, "\r\n", $offset);
        } elseif ('*' === $next) {
            $end = strpos($source, '*/', $offset + 2);
            $end = false === $end ? $length : $end + 2;
        } else {
            return null;
        }
        $comments[] = new JavaScriptToken(JavaScriptTokenKind::Comment, substr($source, $offset, $end - $offset), $offset, $startsLine);

        return $end - 1;
    }

    /** @param list<JavaScriptToken> $tokens */
    private function startsRegularExpression(array $tokens): bool
    {
        $previous = $tokens[\count($tokens) - 1] ?? null;
        if (null === $previous) {
            return true;
        }
        if (JavaScriptTokenKind::Identifier === $previous->kind) {
            return \in_array($previous->value, self::REGULAR_EXPRESSION_KEYWORDS, true);
        }
        if (JavaScriptTokenKind::Punctuator !== $previous->kind) {
            return false;
        }
        if ('>' === $previous->value) {
            $arrow = $tokens[\count($tokens) - 2] ?? null;

            return null !== $arrow && JavaScriptTokenKind::Punctuator === $arrow->kind && '=' === $arrow->value;
        }

        return \in_array($previous->value, self::REGULAR_EXPRESSION_PUNCTUATORS, true);
    }

    private function regularExpressionEnd(string $source, int $offset): ?int
    {
        $characterClass = false;
        for ($end = $offset + 1, $length = \strlen($source); $end < $length; ++$end) {
            $character = $source[$end];
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
                while (isset($source[$end + 1]) && str_contains('dgimsuvy', $source[$end + 1])) {
                    ++$end;
                }

                return $end;
            }
        }

        return null;
    }
}
