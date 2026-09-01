<?php

namespace Symfony\Lsp\Parser\Php;

use Symfony\Lsp\Parser\AbstractCommentParser;
use Symfony\Lsp\Parser\CommentParseResult;
use Symfony\Lsp\Parser\SourceComment;

/**
 * Blanks PHP comments while preserving byte offsets and UTF-16 positions.
 *
 * Only ASCII bytes are replaced with spaces: multibyte sequences keep their
 * byte length and UTF-16 unit count, so positions measured on the masked
 * text always match the original document.
 */
final class PhpCommentParser extends AbstractCommentParser implements PhpCommentParserInterface
{
    protected function parseSource(string $source): CommentParseResult
    {
        $masked = $source;
        $comments = [];
        foreach (\PhpToken::tokenize($source) as $token) {
            if (!$token->is([\T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }
            $end = $token->pos + \strlen($token->text);
            [$contentStart, $contentEnd] = $this->contentOffsets($token->text, $token->pos, $end);
            $comments[] = new SourceComment(
                $token->pos,
                $end,
                $contentStart,
                $contentEnd,
                substr($source, $contentStart, $contentEnd - $contentStart),
            );
            $this->maskRange($masked, $source, $token->pos, $end);
        }

        return new CommentParseResult($masked, $comments);
    }

    /** @return array{int, int} */
    private function contentOffsets(string $comment, int $start, int $end): array
    {
        if (str_starts_with($comment, '//')) {
            return [$start + 2, $end];
        }
        if (str_starts_with($comment, '#')) {
            return [$start + 1, $end];
        }

        return [$start + 2, str_ends_with($comment, '*/') ? $end - 2 : $end];
    }
}
