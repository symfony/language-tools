<?php

namespace Symfony\Lsp\Parser\Yaml;

/**
 * Blanks YAML comments while preserving byte offsets and UTF-16 positions.
 *
 * Only ASCII bytes are replaced with spaces: multibyte sequences keep their
 * byte length and UTF-16 unit count, so positions measured on the masked
 * text always match the original document.
 */
final class YamlCommentParser
{
    public function mask(string $source): string
    {
        $masked = $source;
        $length = \strlen($source);
        $quote = null;

        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $source[$offset];
            if (null !== $quote) {
                if ('"' === $quote && '\\' === $character) {
                    ++$offset;
                    continue;
                }
                if ($quote !== $character) {
                    continue;
                }
                if ('\'' === $quote && '\'' === ($source[$offset + 1] ?? null)) {
                    ++$offset;
                    continue;
                }
                $quote = null;
                continue;
            }
            if (('\'' === $character || '"' === $character) && $this->startsQuotedScalar($source, $offset)) {
                $quote = $character;
                continue;
            }
            if ('#' !== $character || (0 !== $offset && !str_contains(" \t\r\n", $source[$offset - 1]))) {
                continue;
            }
            $end = $offset + strcspn($source, "\r\n", $offset);
            $this->maskRange($masked, $source, $offset, $end);
            $offset = $end - 1;
        }

        return $masked;
    }

    private function startsQuotedScalar(string $source, int $offset): bool
    {
        return 0 === $offset || str_contains(" \t\r\n-?:,[{", $source[$offset - 1]);
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
