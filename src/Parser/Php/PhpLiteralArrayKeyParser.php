<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpLiteralArrayKeyParser
{
    /** @return list<string>|null */
    public function parse(string $items, bool $allowNestedUnpacking): ?array
    {
        $keys = [];
        $depth = 0;
        $literalKey = null;
        $keyIsLiteral = true;
        $keyParsed = false;
        foreach (token_get_all('<?php '.$items) as $token) {
            if (\is_array($token)) {
                if (\T_ELLIPSIS === $token[0] && (!$allowNestedUnpacking || 0 === $depth)) {
                    return null;
                }
                if (\in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    if (0 === $depth && !$keyParsed) {
                        $keyIsLiteral = false;
                    }
                    ++$depth;

                    continue;
                }
                if (0 !== $depth || \in_array($token[0], [\T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }
                if (\T_DOUBLE_ARROW === $token[0] && !$keyParsed) {
                    if (!$keyIsLiteral || null === $literalKey) {
                        return null;
                    }
                    $keys[] = PhpStringLiteralDecoder::decode($literalKey[0], substr($literalKey, 1, -1));
                    $keyParsed = true;
                } elseif (!$keyParsed) {
                    if (\T_CONSTANT_ENCAPSED_STRING === $token[0] && null === $literalKey) {
                        $literalKey = $token[1];
                    } else {
                        $keyIsLiteral = false;
                    }
                }

                continue;
            }
            if (\in_array($token, ['(', '[', '{'], true)) {
                if (0 === $depth && !$keyParsed) {
                    $keyIsLiteral = false;
                }
                ++$depth;
            } elseif (\in_array($token, [')', ']', '}'], true)) {
                --$depth;
            } elseif (0 === $depth && ',' === $token) {
                $literalKey = null;
                $keyIsLiteral = true;
                $keyParsed = false;
            } elseif (0 === $depth && !$keyParsed) {
                $keyIsLiteral = false;
            }
        }

        return $keys;
    }
}
