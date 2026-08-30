<?php

namespace Symfony\Lsp\Parser\Yaml;

final class YamlScalarDecoder
{
    public function style(string $nodeType, string $raw): YamlScalarStyle
    {
        return match ($nodeType) {
            'single_quote_scalar' => YamlScalarStyle::SingleQuoted,
            'double_quote_scalar' => YamlScalarStyle::DoubleQuoted,
            'block_scalar' => str_starts_with($raw, '|') ? YamlScalarStyle::BlockLiteral : YamlScalarStyle::BlockFolded,
            default => YamlScalarStyle::Plain,
        };
    }

    /** @return array{int, int} */
    public function contentOffsets(string $raw, int $start, int $end, YamlScalarStyle $style, int $baseIndent = 0): array
    {
        if (\in_array($style, [YamlScalarStyle::SingleQuoted, YamlScalarStyle::DoubleQuoted], true)) {
            return [$start + 1, str_ends_with($raw, $raw[0]) && 1 < \strlen($raw) ? $end - 1 : $end];
        }
        if (\in_array($style, [YamlScalarStyle::BlockLiteral, YamlScalarStyle::BlockFolded], true)) {
            $newline = strcspn($raw, "\r\n");
            if ($newline === \strlen($raw)) {
                return [$end, $end];
            }
            $body = $newline + ("\r" === $raw[$newline] && "\n" === ($raw[$newline + 1] ?? null) ? 2 : 1);
            $bodyText = substr($raw, $body);
            $lines = preg_split('/\R/', $bodyText);
            $indent = false === $lines ? 0 : $this->blockIndent(substr($raw, 0, $newline), $lines, $baseIndent);
            $content = $body;
            for ($remaining = $indent; 0 < $remaining && isset($raw[$content]) && \in_array($raw[$content], [' ', "\t"], true); --$remaining) {
                ++$content;
            }

            return [$start + $content, $end];
        }

        return [$start, $end];
    }

    public function decode(string $raw, YamlScalarStyle $style, int $baseIndent = 0): string
    {
        return match ($style) {
            YamlScalarStyle::Plain => $this->foldFlowLines($raw),
            YamlScalarStyle::SingleQuoted => str_replace("''", "'", $this->foldFlowLines(substr($raw, 1, str_ends_with($raw, "'") ? -1 : null))),
            YamlScalarStyle::DoubleQuoted => $this->decodeDoubleQuoted(substr($raw, 1, str_ends_with($raw, '"') ? -1 : null)),
            YamlScalarStyle::BlockLiteral, YamlScalarStyle::BlockFolded => $this->decodeBlock($raw, $style, $baseIndent),
        };
    }

    private function foldFlowLines(string $value): string
    {
        $lines = preg_split('/\R/', $value);
        if (false === $lines || 1 === \count($lines)) {
            return $value;
        }
        $result = array_shift($lines);
        $empty = 0;
        foreach ($lines as $line) {
            $line = ltrim($line, " \t");
            if ('' === $line) {
                ++$empty;
                continue;
            }
            $result .= 0 === $empty ? ' ' : str_repeat("\n", $empty);
            $result .= $line;
            $empty = 0;
        }

        return $result.str_repeat("\n", $empty);
    }

    private function decodeDoubleQuoted(string $value): string
    {
        $result = '';
        for ($index = 0, $length = \strlen($value); $index < $length; ++$index) {
            $character = $value[$index];
            if ('\\' !== $character) {
                if ("\r" === $character || "\n" === $character) {
                    $lineBreakLength = "\r" === $character && "\n" === ($value[$index + 1] ?? null) ? 2 : 1;
                    $index += $lineBreakLength - 1;
                    while (isset($value[$index + 1]) && \in_array($value[$index + 1], [' ', "\t"], true)) {
                        ++$index;
                    }
                    $result .= ' ';
                } else {
                    $result .= $character;
                }
                continue;
            }
            $escape = $value[++$index] ?? '';
            if ("\r" === $escape || "\n" === $escape) {
                if ("\r" === $escape && "\n" === ($value[$index + 1] ?? null)) {
                    ++$index;
                }
                while (isset($value[$index + 1]) && \in_array($value[$index + 1], [' ', "\t"], true)) {
                    ++$index;
                }
                continue;
            }
            $decoded = match ($escape) {
                '0' => "\0",
                'a' => "\x07",
                'b' => "\x08",
                't', "\t" => "\t",
                'n' => "\n",
                'v' => "\x0B",
                'f' => "\f",
                'r' => "\r",
                'e' => "\x1B",
                ' ' => ' ',
                '"' => '"',
                '/' => '/',
                '\\' => '\\',
                'N' => "\u{0085}",
                '_' => "\u{00A0}",
                'L' => "\u{2028}",
                'P' => "\u{2029}",
                default => null,
            };
            if (null !== $decoded) {
                $result .= $decoded;
                continue;
            }
            $digits = match ($escape) {
                'x' => 2,
                'u' => 4,
                'U' => 8,
                default => 0,
            };
            if (0 < $digits) {
                $hex = substr($value, $index + 1, $digits);
                if ($digits === \strlen($hex) && ctype_xdigit($hex)) {
                    $result .= $this->codePoint((int) hexdec($hex));
                    $index += $digits;
                    continue;
                }
            }
            $result .= '\\'.$escape;
        }

        return $result;
    }

    private function codePoint(int $value): string
    {
        if ($value <= 0x7F) {
            return \chr($value & 0x7F);
        }
        if ($value <= 0x7FF) {
            return \chr(0xC0 | ($value >> 6)).\chr(0x80 | ($value & 0x3F));
        }
        if ($value <= 0xFFFF) {
            return \chr(0xE0 | ($value >> 12)).\chr(0x80 | (($value >> 6) & 0x3F)).\chr(0x80 | ($value & 0x3F));
        }
        if ($value > 0x10FFFF) {
            return "\u{FFFD}";
        }

        return \chr(0xF0 | (($value >> 18) & 0x07)).\chr(0x80 | (($value >> 12) & 0x3F)).\chr(0x80 | (($value >> 6) & 0x3F)).\chr(0x80 | ($value & 0x3F));
    }

    private function decodeBlock(string $raw, YamlScalarStyle $style, int $baseIndent): string
    {
        $headerLength = strcspn($raw, "\r\n");
        $header = substr($raw, 0, $headerLength);
        if ($headerLength === \strlen($raw)) {
            return '';
        }
        $bodyOffset = $headerLength + ("\r" === $raw[$headerLength] && "\n" === ($raw[$headerLength + 1] ?? null) ? 2 : 1);
        $body = substr($raw, $bodyOffset);
        $lines = preg_split('/\R/', $body);
        if (false === $lines) {
            return $body;
        }
        $indent = $this->blockIndent($header, $lines, $baseIndent);
        foreach ($lines as &$line) {
            $line = substr($line, min($indent, \strlen($line)));
        }
        unset($line);

        $value = YamlScalarStyle::BlockLiteral === $style ? implode("\n", $lines) : $this->foldBlockLines($lines);
        $chomping = str_contains($header, '-') ? '-' : (str_contains($header, '+') ? '+' : '');
        if ('-' === $chomping) {
            return rtrim($value, "\n");
        }
        if ('+' === $chomping) {
            return $value."\n";
        }

        return rtrim($value, "\n")."\n";
    }

    /** @param list<string> $lines */
    private function blockIndent(string $header, array $lines, int $baseIndent): int
    {
        if (preg_match('/[1-9]/', $header, $match)) {
            return $baseIndent + (int) $match[0];
        }
        $indent = null;
        foreach ($lines as $line) {
            if ('' === trim($line)) {
                continue;
            }
            $lineIndent = \strlen($line) - \strlen(ltrim($line, " \t"));
            $indent = null === $indent ? $lineIndent : min($indent, $lineIndent);
        }

        return $indent ?? 0;
    }

    /** @param list<string> $lines */
    private function foldBlockLines(array $lines): string
    {
        $result = '';
        foreach ($lines as $index => $line) {
            if (0 < $index) {
                $previous = $lines[$index - 1];
                $moreIndented = '' !== $line && ctype_space($line[0]);
                $previousMoreIndented = '' !== $previous && ctype_space($previous[0]);
                $result .= '' !== $previous && '' !== $line && !$moreIndented && !$previousMoreIndented ? ' ' : "\n";
            }
            $result .= $line;
        }

        return $result;
    }
}
