<?php

namespace Symfony\Lsp\Parser\Xml;

/**
 * Blanks XML comments while preserving byte offsets and UTF-16 positions.
 *
 * Only ASCII bytes are replaced with spaces: multibyte sequences keep their
 * byte length and UTF-16 unit count, so positions measured on the masked
 * text always match the original document.
 */
final class XmlCommentParser
{
    public function mask(string $source): string
    {
        $masked = $source;
        $length = \strlen($source);

        for ($offset = 0; $offset < $length;) {
            $start = strpos($source, '<!--', $offset);
            if (false === $start) {
                break;
            }
            $closing = strpos($source, '-->', $start + 4);
            $end = false === $closing ? $length : $closing + 3;
            $this->maskRange($masked, $source, $start, $end);
            $offset = $end;
        }

        return $masked;
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
