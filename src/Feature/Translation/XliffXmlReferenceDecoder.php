<?php

namespace Symfony\Lsp\Feature\Translation;

final class XliffXmlReferenceDecoder
{
    public function decode(string $value): string
    {
        return preg_replace_callback(
            '/&(?:amp|lt|gt|apos|quot|#[0-9]+|#x[0-9A-Fa-f]+);/',
            function (array $match): string {
                return match ($match[0]) {
                    '&amp;' => '&',
                    '&lt;' => '<',
                    '&gt;' => '>',
                    '&apos;' => "'",
                    '&quot;' => '"',
                    default => $this->numeric($match[0]),
                };
            },
            $value,
        ) ?? $value;
    }

    private function numeric(string $reference): string
    {
        $hexadecimal = '#x' === substr($reference, 1, 2);
        $digits = substr($reference, $hexadecimal ? 3 : 2, -1);
        $codePoint = \intval($digits, $hexadecimal ? 16 : 10);
        if (!$this->isXmlCodePoint($codePoint)) {
            return $reference;
        }
        if ($codePoint <= 0x7F) {
            return \chr($codePoint & 0xFF);
        }
        if ($codePoint <= 0x7FF) {
            return \chr(0xC0 | ($codePoint >> 6)).\chr(0x80 | ($codePoint & 0x3F));
        }
        if ($codePoint <= 0xFFFF) {
            return \chr(0xE0 | ($codePoint >> 12)).\chr(0x80 | (($codePoint >> 6) & 0x3F)).\chr(0x80 | ($codePoint & 0x3F));
        }

        return \chr((0xF0 | ($codePoint >> 18)) & 0xFF).\chr(0x80 | (($codePoint >> 12) & 0x3F)).\chr(0x80 | (($codePoint >> 6) & 0x3F)).\chr(0x80 | ($codePoint & 0x3F));
    }

    private function isXmlCodePoint(int $codePoint): bool
    {
        return 0x09 === $codePoint
            || 0x0A === $codePoint
            || 0x0D === $codePoint
            || ($codePoint >= 0x20 && $codePoint <= 0xD7FF)
            || ($codePoint >= 0xE000 && $codePoint <= 0xFFFD)
            || ($codePoint >= 0x10000 && $codePoint <= 0x10FFFF);
    }
}
