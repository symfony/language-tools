<?php

namespace Symfony\Lsp\Parser\Html;

use Symfony\Lsp\Parser\AbstractCommentParser;
use Symfony\Lsp\Parser\CommentParseResult;
use Symfony\Lsp\Parser\SourceComment;

/**
 * Blanks HTML comments while preserving byte offsets and UTF-16 positions.
 *
 * Only ASCII bytes are replaced with spaces: multibyte sequences keep their
 * byte length and UTF-16 unit count, so positions measured on the masked
 * text always match the original document.
 */
final class HtmlCommentParser extends AbstractCommentParser
{
    protected function parseSource(string $source): CommentParseResult
    {
        $masked = $source;
        $comments = [];
        $length = \strlen($source);
        $offset = 0;
        while (false !== $start = strpos($source, '<!--', $offset)) {
            $closing = strpos($source, '-->', $start + 4);
            $contentStart = $start + 4;
            $contentEnd = false === $closing ? $length : $closing;
            $end = false === $closing ? $length : $closing + 3;
            $comments[] = new SourceComment(
                $start,
                $end,
                $contentStart,
                $contentEnd,
                substr($source, $contentStart, $contentEnd - $contentStart),
            );
            $this->maskRange($masked, $source, $start, $end);
            $offset = $end;
        }

        return new CommentParseResult($masked, $comments);
    }
}
