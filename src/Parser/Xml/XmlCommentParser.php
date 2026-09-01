<?php

namespace Symfony\Lsp\Parser\Xml;

use Symfony\Lsp\Parser\AbstractCommentParser;
use Symfony\Lsp\Parser\CommentParseResult;
use Symfony\Lsp\Parser\SourceComment;

/**
 * Blanks XML comments while preserving byte offsets and UTF-16 positions.
 *
 * Only ASCII bytes are replaced with spaces: multibyte sequences keep their
 * byte length and UTF-16 unit count, so positions measured on the masked
 * text always match the original document.
 */
final class XmlCommentParser extends AbstractCommentParser
{
    protected function parseSource(string $source): CommentParseResult
    {
        $masked = $source;
        $comments = [];
        $length = \strlen($source);

        for ($offset = 0; $offset < $length;) {
            $start = strpos($source, '<!--', $offset);
            $cdata = strpos($source, '<![CDATA[', $offset);
            if (false !== $cdata && (false === $start || $cdata < $start)) {
                $closing = strpos($source, ']]>', $cdata + 9);
                $offset = false === $closing ? $length : $closing + 3;

                continue;
            }
            if (false === $start) {
                break;
            }
            $closing = strpos($source, '-->', $start + 4);
            $end = false === $closing ? $length : $closing + 3;
            $contentEnd = false === $closing ? $length : $closing;
            $comments[] = new SourceComment(
                $start,
                $end,
                $start + 4,
                $contentEnd,
                substr($source, $start + 4, $contentEnd - $start - 4),
            );
            $this->maskRange($masked, $source, $start, $end);
            $offset = $end;
        }

        return new CommentParseResult($masked, $comments);
    }
}
