<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class Utf16Positions
{
    /**
     * @param array<array-key, mixed> $position
     *
     * @throws ScenarioStepException when the position does not address a character boundary of the text
     */
    public function byteOffset(string $text, array $position): int
    {
        $line = $position['line'] ?? null;
        $character = $position['character'] ?? null;
        if (!\is_int($line) || !\is_int($character) || 0 > $line || 0 > $character) {
            throw new ScenarioStepException(\sprintf('Position %s is not a pair of non-negative integers.', json_encode($position)));
        }
        $lineStarts = $this->lineStarts($text);
        if (!isset($lineStarts[$line])) {
            throw new ScenarioStepException(\sprintf('Line %d is outside the %d line document.', $line, \count($lineStarts)));
        }
        $lineStart = $lineStarts[$line];
        $lineText = substr($text, $lineStart, $this->lineContentEnd($text, $lineStarts, $line) - $lineStart);
        $units = 0;
        $bytes = 0;
        foreach (mb_str_split($lineText) as $item) {
            if ($units === $character) {
                break;
            }
            $itemUnits = $this->units($item);
            if ($units + $itemUnits > $character) {
                throw new ScenarioStepException(\sprintf('Character %d of line %d splits a UTF-16 surrogate pair.', $character, $line));
            }
            $units += $itemUnits;
            $bytes += \strlen($item);
        }
        if ($units !== $character) {
            throw new ScenarioStepException(\sprintf('Character %d is beyond the %d UTF-16 code units of line %d.', $character, $units, $line));
        }

        return $lineStart + $bytes;
    }

    private function units(string $text): int
    {
        return intdiv(\strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * @return list<int>
     */
    private function lineStarts(string $text): array
    {
        $lineStarts = [0];
        $offset = 0;
        while (false !== $offset = strpos($text, "\n", $offset)) {
            $lineStarts[] = ++$offset;
        }

        return $lineStarts;
    }

    /**
     * @param list<int> $lineStarts
     */
    private function lineContentEnd(string $text, array $lineStarts, int $line): int
    {
        $lineStart = $lineStarts[$line];
        $lineEnd = $lineStarts[$line + 1] ?? \strlen($text);
        if ($lineEnd > $lineStart && "\n" === $text[$lineEnd - 1]) {
            --$lineEnd;
            if ($lineEnd > $lineStart && "\r" === $text[$lineEnd - 1]) {
                --$lineEnd;
            }
        }

        return $lineEnd;
    }
}
