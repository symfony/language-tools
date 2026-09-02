<?php

namespace Symfony\Lsp\Document;

final class DocumentPositionMap
{
    /** @var list<int> */
    private array $lineStarts = [0];

    public function __construct(
        private readonly string $text,
        private readonly string $encoding,
    ) {
        $offset = 0;
        while (false !== $offset = strpos($this->text, "\n", $offset)) {
            $this->lineStarts[] = ++$offset;
        }
    }

    public function toByteOffset(Position $position): int
    {
        if ($position->line >= \count($this->lineStarts)) {
            return \strlen($this->text);
        }

        $lineStart = $this->lineStarts[$position->line];
        $lineEnd = $this->lineContentEnd($position->line);
        $lineText = substr($this->text, $lineStart, $lineEnd - $lineStart);
        if ('utf-8' === $this->encoding) {
            return $lineStart + min($position->character, \strlen($lineText));
        }

        $byteOffset = 0;
        $characterOffset = 0;
        foreach (mb_str_split($lineText) as $character) {
            $units = 'utf-32' === $this->encoding ? 1 : \strlen(mb_convert_encoding($character, 'UTF-16LE', 'UTF-8')) / 2;
            if ($characterOffset + $units > $position->character) {
                break;
            }

            $characterOffset += $units;
            $byteOffset += \strlen($character);
        }

        return $lineStart + $byteOffset;
    }

    public function toPosition(int $byteOffset): Position
    {
        $byteOffset = max(0, min($byteOffset, \strlen($this->text)));
        $line = $this->lineAt($byteOffset);
        $lineStart = $this->lineStarts[$line];
        $byteOffset = min($byteOffset, $this->lineContentEnd($line));
        $linePrefix = substr($this->text, $lineStart, $byteOffset - $lineStart);
        $character = match ($this->encoding) {
            'utf-8' => \strlen($linePrefix),
            'utf-32' => mb_strlen($linePrefix),
            default => \strlen(mb_convert_encoding($linePrefix, 'UTF-16LE', 'UTF-8')) / 2,
        };

        return new Position($line, (int) $character);
    }

    private function lineContentEnd(int $line): int
    {
        $lineStart = $this->lineStarts[$line];
        $lineEnd = $this->lineStarts[$line + 1] ?? \strlen($this->text);
        if ($lineEnd > $lineStart && "\n" === $this->text[$lineEnd - 1]) {
            --$lineEnd;
            if ($lineEnd > $lineStart && "\r" === $this->text[$lineEnd - 1]) {
                --$lineEnd;
            }
        }

        return $lineEnd;
    }

    private function lineAt(int $byteOffset): int
    {
        $low = 0;
        $high = \count($this->lineStarts) - 1;

        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if ($this->lineStarts[$middle] <= $byteOffset) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $low;
    }
}
