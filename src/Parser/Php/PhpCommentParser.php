<?php

namespace Symfony\Lsp\Parser\Php;

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
                if ("\r" !== $masked[$offset] && "\n" !== $masked[$offset]) {
                    $masked[$offset] = ' ';
                }
            }
        }

        return $masked;
    }
}
