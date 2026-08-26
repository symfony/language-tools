<?php

namespace Symfony\Lsp\Document;

final class PositionConverter
{
    private string $encoding = 'utf-16';
    private ?string $mappedText = null;
    private ?DocumentPositionMap $positionMap = null;

    /** @param list<mixed> $offered */
    public function negotiate(array $offered): string
    {
        foreach ($offered as $encoding) {
            if (\is_string($encoding) && \in_array(strtolower($encoding), ['utf-8', 'utf-16', 'utf-32'], true)) {
                return $this->setEncoding(strtolower($encoding));
            }
        }

        return $this->setEncoding('utf-16');
    }

    public function encoding(): string
    {
        return $this->encoding;
    }

    public function toByteOffset(string $text, Position $position): int
    {
        return $this->map($text)->toByteOffset($position);
    }

    public function toPosition(string $text, int $byteOffset): Position
    {
        return $this->map($text)->toPosition($byteOffset);
    }

    public function toRange(string $text, int $byteOffset, int $byteLength): Range
    {
        $map = $this->map($text);

        return new Range($map->toPosition($byteOffset), $map->toPosition($byteOffset + $byteLength));
    }

    public function containsByteOffset(string $text, Range $range, int $byteOffset, bool $inclusiveEnd = false): bool
    {
        $map = $this->map($text);
        $start = $map->toByteOffset($range->start());
        $end = $map->toByteOffset($range->end());

        return $byteOffset >= $start && ($inclusiveEnd ? $byteOffset <= $end : $byteOffset < $end);
    }

    public function applyChange(string $text, Range $range, string $replacement): string
    {
        $start = $this->toByteOffset($text, $range->start());
        $end = $this->toByteOffset($text, $range->end());

        return substr($text, 0, $start).$replacement.substr($text, $end);
    }

    private function setEncoding(string $encoding): string
    {
        if ($encoding !== $this->encoding) {
            $this->mappedText = null;
            $this->positionMap = null;
        }

        return $this->encoding = $encoding;
    }

    private function map(string $text): DocumentPositionMap
    {
        if ($text !== $this->mappedText || null === $this->positionMap) {
            $this->mappedText = $text;
            $this->positionMap = new DocumentPositionMap($text, $this->encoding);
        }

        return $this->positionMap;
    }
}
