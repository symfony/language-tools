<?php

namespace Symfony\Lsp\Parser\Xml;

use Symfony\Lsp\Parser\CommentParseResult;
use Symfony\Lsp\Parser\CommentParserInterface;
use Symfony\Lsp\Parser\SourceComment;

/**
 * Blanks XML comments while preserving byte offsets and UTF-16 positions.
 *
 * Only ASCII bytes are replaced with spaces: multibyte sequences keep their
 * byte length and UTF-16 unit count, so positions measured on the masked
 * text always match the original document.
 */
final class XmlCommentParser implements CommentParserInterface
{
    private ?string $lastSource = null;
    private ?CommentParseResult $lastResult = null;

    public function parse(string $source): CommentParseResult
    {
        if ($source === $this->lastSource && null !== $this->lastResult) {
            return $this->lastResult;
        }

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

        $this->lastSource = $source;

        return $this->lastResult = new CommentParseResult($masked, $comments);
    }

    public function mask(string $source): string
    {
        return $this->parse($source)->masked;
    }

    public function comments(string $source): array
    {
        return $this->parse($source)->comments;
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
