<?php

namespace Symfony\Lsp\Parser\Php;

/**
 * Blanks PHP comments while preserving byte offsets and UTF-16 positions.
 *
 * Only ASCII bytes are replaced with spaces: multibyte sequences keep their
 * byte length and UTF-16 unit count, so positions measured on the masked
 * text always match the original document.
 */
final class PhpCommentParser implements PhpCommentParserInterface
{
    public function mask(string $source): string
    {
        $masked = $source;
        foreach (\PhpToken::tokenize($source) as $token) {
            if (!$token->is([\T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }
            $end = $token->pos + \strlen($token->text);
            for ($offset = $token->pos; $offset < $end; ++$offset) {
                $byte = $masked[$offset];
                if ("\r" !== $byte && "\n" !== $byte && \ord($byte) < 0x80) {
                    $masked[$offset] = ' ';
                }
            }
        }

        return $masked;
    }
}
