<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpLiteralArrayKeyParser
{
    /** @return list<PhpStringLiteral>|null */
    public function parse(string $items, bool $allowNestedUnpacking, bool $collectPartialLiteralKeys = false, int $sourceOffset = 0): ?array
    {
        $prefix = '<?php ';
        $keys = [];
        $depth = 0;
        $literalKey = null;
        $keyIsLiteral = true;
        $keyParsed = false;
        foreach (\PhpToken::tokenize($prefix.$items) as $token) {
            if (\T_ELLIPSIS === $token->id && (!$allowNestedUnpacking || 0 === $depth)) {
                if ($collectPartialLiteralKeys && 0 === $depth) {
                    $keyParsed = true;

                    continue;
                }

                return null;
            }
            if (\in_array($token->id, [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                if (0 === $depth && !$keyParsed) {
                    $keyIsLiteral = false;
                }
                ++$depth;

                continue;
            }
            if (\in_array($token->text, ['(', '[', '{'], true)) {
                if (0 === $depth && !$keyParsed) {
                    $keyIsLiteral = false;
                }
                ++$depth;

                continue;
            }
            if (\in_array($token->text, [')', ']', '}'], true)) {
                --$depth;

                continue;
            }
            if (0 !== $depth || $token->is([\T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }
            if (\T_DOUBLE_ARROW === $token->id && !$keyParsed) {
                if (!$keyIsLiteral || null === $literalKey) {
                    if (!$collectPartialLiteralKeys) {
                        return null;
                    }
                } else {
                    $startOffset = $sourceOffset + $literalKey->pos - \strlen($prefix) + 1;
                    $keys[] = new PhpStringLiteral(
                        PhpStringLiteralDecoder::decode($literalKey->text[0], substr($literalKey->text, 1, -1)),
                        $startOffset,
                        $startOffset + \strlen($literalKey->text) - 2,
                    );
                }
                $keyParsed = true;
            } elseif (!$keyParsed && \T_CONSTANT_ENCAPSED_STRING === $token->id && null === $literalKey) {
                $literalKey = $token;
            } elseif (',' === $token->text) {
                $literalKey = null;
                $keyIsLiteral = true;
                $keyParsed = false;
            } elseif (!$keyParsed) {
                $keyIsLiteral = false;
            }
        }

        return $keys;
    }
}
