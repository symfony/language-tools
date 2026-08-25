<?php

namespace Symfony\Lsp\Feature\Translation;

final class TwigStringDecoder
{
    private const SPECIAL_CHARACTERS = [
        'f' => "\f",
        'n' => "\n",
        'r' => "\r",
        't' => "\t",
        'v' => "\v",
    ];

    public static function decode(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
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
            if (isset(self::SPECIAL_CHARACTERS[$character])) {
                $result .= self::SPECIAL_CHARACTERS[$character];
            } elseif ('\\' === $character || "'" === $character || '"' === $character) {
                $result .= $character;
            } elseif ('#' === $character && '{' === ($value[$offset + 1] ?? null)) {
                $result .= '#{';
                ++$offset;
            } elseif ('x' === $character && ctype_xdigit($value[$offset + 1] ?? '')) {
                $hexadecimal = $value[++$offset];
                if (ctype_xdigit($value[$offset + 1] ?? '')) {
                    $hexadecimal .= $value[++$offset];
                }
                $result .= \chr(((int) hexdec($hexadecimal)) & 0xFF);
            } elseif (ctype_digit($character) && $character < '8') {
                $octal = $character;
                while (\strlen($octal) < 3 && ctype_digit($value[$offset + 1] ?? '') && $value[$offset + 1] < '8') {
                    $octal .= $value[++$offset];
                }
                $result .= \chr(((int) octdec($octal)) & 0xFF);
            } else {
                $result .= $character;
            }

            ++$offset;
        }

        return $result;
    }
}
