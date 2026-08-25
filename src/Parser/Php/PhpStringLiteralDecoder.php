<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpStringLiteralDecoder
{
    private const ESCAPES = [
        '\\' => '\\',
        '"' => '"',
        '$' => '$',
        'n' => "\n",
        'r' => "\r",
        't' => "\t",
        'v' => "\v",
        'e' => "\e",
        'f' => "\f",
    ];

    public static function decode(string $quote, string $value): string
    {
        return "'" === $quote ? self::decodeSingleQuoted($value) : self::decodeDoubleQuoted($value);
    }

    public static function decodeSingleQuoted(string $value): string
    {
        return strtr($value, ['\\\\' => '\\', "\\'" => "'"]);
    }

    public static function decodeDoubleQuoted(string $value): string
    {
        $result = '';
        $length = \strlen($value);
        $offset = 0;
        while ($offset < $length) {
            $position = strpos($value, '\\', $offset);
            if (false === $position) {
                $result .= substr($value, $offset);
                break;
            }

            $result .= substr($value, $offset, $position - $offset);
            $offset = $position + 1;
            if ($offset >= $length) {
                $result .= '\\';
                break;
            }

            $character = $value[$offset];
            if (isset(self::ESCAPES[$character])) {
                $result .= self::ESCAPES[$character];
            } elseif ('x' === $character && ctype_xdigit($value[$offset + 1] ?? '')) {
                $hexadecimal = $value[++$offset];
                if (ctype_xdigit($value[$offset + 1] ?? '')) {
                    $hexadecimal .= $value[++$offset];
                }
                $result .= \chr(((int) hexdec($hexadecimal)) & 0xFF);
            } elseif ($character >= '0' && $character <= '7') {
                $octal = $character;
                while (\strlen($octal) < 3) {
                    $next = $value[$offset + 1] ?? '';
                    if (!ctype_digit($next) || $next >= '8') {
                        break;
                    }
                    $octal .= $next;
                    ++$offset;
                }
                $result .= \chr(((int) octdec($octal)) & 0xFF);
            } elseif ('u' === $character && '{' === ($value[$offset + 1] ?? null)) {
                $closing = strpos($value, '}', $offset + 2);
                if (false === $closing) {
                    $result .= '\\u';
                } else {
                    $hexadecimal = substr($value, $offset + 2, $closing - $offset - 2);
                    $normalized = ltrim($hexadecimal, '0');
                    /** @var int<0, 16777215> $codepoint */
                    $codepoint = '' !== $hexadecimal && ctype_xdigit($hexadecimal) && \strlen($normalized) <= 6
                        ? (int) hexdec($hexadecimal)
                        : 0x110000;
                    if ($codepoint <= 0x10FFFF) {
                        $result .= self::utf8($codepoint);
                    } else {
                        $result .= substr($value, $position, $closing - $position + 1);
                    }
                    $offset = $closing;
                }
            } else {
                $result .= '\\'.$character;
            }

            ++$offset;
        }

        return $result;
    }

    /** @param int<0, 1114111> $codepoint */
    private static function utf8(int $codepoint): string
    {
        if ($codepoint <= 0x7F) {
            return \chr($codepoint);
        }
        if ($codepoint <= 0x7FF) {
            return \chr(0xC0 | ($codepoint >> 6))
                .\chr(0x80 | ($codepoint & 0x3F));
        }
        if ($codepoint <= 0xFFFF) {
            return \chr(0xE0 | ($codepoint >> 12))
                .\chr(0x80 | (($codepoint >> 6) & 0x3F))
                .\chr(0x80 | ($codepoint & 0x3F));
        }

        return \chr(0xF0 | ($codepoint >> 18))
            .\chr(0x80 | (($codepoint >> 12) & 0x3F))
            .\chr(0x80 | (($codepoint >> 6) & 0x3F))
            .\chr(0x80 | ($codepoint & 0x3F));
    }
}
