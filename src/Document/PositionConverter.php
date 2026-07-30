<?php

namespace Symfony\Lsp\Document;

final class PositionConverter
{
    public function toByteOffset(string $text, Position $position): int
    {
        $lines = explode("\n", $text);
        if ($position->line() >= \count($lines)) {
            return \strlen($text);
        }

        $lineOffset = 0;
        for ($line = 0; $line < $position->line(); ++$line) {
            $lineOffset += \strlen($lines[$line]) + 1;
        }

        $lineText = $lines[$position->line()];
        $byteOffset = 0;
        $utf16Offset = 0;
        foreach (mb_str_split($lineText) as $character) {
            $units = \strlen(mb_convert_encoding($character, 'UTF-16LE', 'UTF-8')) / 2;
            if ($utf16Offset + $units > $position->character()) {
                break;
            }

            $utf16Offset += $units;
            $byteOffset += \strlen($character);
        }

        return $lineOffset + $byteOffset;
    }

    public function toPosition(string $text, int $byteOffset): Position
    {
        $prefix = substr($text, 0, max(0, min($byteOffset, \strlen($text))));
        $line = substr_count($prefix, "\n");
        $lineStart = strrpos($prefix, "\n");
        $linePrefix = false === $lineStart ? $prefix : substr($prefix, $lineStart + 1);
        $character = \strlen(mb_convert_encoding($linePrefix, 'UTF-16LE', 'UTF-8')) / 2;

        return new Position($line, (int) $character);
    }

    public function applyChange(string $text, Range $range, string $replacement): string
    {
        $start = $this->toByteOffset($text, $range->start());
        $end = $this->toByteOffset($text, $range->end());

        return substr($text, 0, $start).$replacement.substr($text, $end);
    }
}
