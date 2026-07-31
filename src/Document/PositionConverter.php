<?php

namespace Symfony\Lsp\Document;

final class PositionConverter
{
    private string $encoding = 'utf-16';

    /** @param list<mixed> $offered */
    public function negotiate(array $offered): string
    {
        foreach ($offered as $encoding) {
            if (\is_string($encoding) && \in_array(strtolower($encoding), ['utf-8', 'utf-16', 'utf-32'], true)) {
                return $this->encoding = strtolower($encoding);
            }
        }

        return $this->encoding = 'utf-16';
    }

    public function encoding(): string
    {
        return $this->encoding;
    }

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
        if ('utf-8' === $this->encoding) {
            return $lineOffset + min($position->character(), \strlen($lineText));
        }

        $byteOffset = 0;
        $characterOffset = 0;
        foreach (mb_str_split($lineText) as $character) {
            $units = 'utf-32' === $this->encoding ? 1 : \strlen(mb_convert_encoding($character, 'UTF-16LE', 'UTF-8')) / 2;
            if ($characterOffset + $units > $position->character()) {
                break;
            }

            $characterOffset += $units;
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
        $character = match ($this->encoding) {
            'utf-8' => \strlen($linePrefix),
            'utf-32' => mb_strlen($linePrefix),
            default => \strlen(mb_convert_encoding($linePrefix, 'UTF-16LE', 'UTF-8')) / 2,
        };

        return new Position($line, (int) $character);
    }

    public function applyChange(string $text, Range $range, string $replacement): string
    {
        $start = $this->toByteOffset($text, $range->start());
        $end = $this->toByteOffset($text, $range->end());

        return substr($text, 0, $start).$replacement.substr($text, $end);
    }
}
