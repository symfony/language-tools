<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class Probe
{
    public function __construct(
        public readonly string $category,
        public readonly string $path,
        public readonly string $contents,
        public readonly string $value,
        public readonly int $line,
        public readonly int $character,
    ) {
    }

    public function countCoveringLinks(mixed $links): int
    {
        if (!\is_array($links) || !array_is_list($links)) {
            return 0;
        }
        $count = 0;
        foreach ($links as $link) {
            if (\is_array($link) && $this->covers($link['range'] ?? null)) {
                ++$count;
            }
        }

        return $count;
    }

    private function covers(mixed $range): bool
    {
        if (!\is_array($range)) {
            return false;
        }
        $start = $this->comparedTo($range['start'] ?? null);
        $end = $this->comparedTo($range['end'] ?? null);

        return null !== $start && null !== $end && 0 <= $start && 0 > $end;
    }

    private function comparedTo(mixed $position): ?int
    {
        if (!\is_array($position)) {
            return null;
        }
        $line = $position['line'] ?? null;
        $character = $position['character'] ?? null;
        if (!\is_int($line) || !\is_int($character) || 0 > $line || 0 > $character) {
            return null;
        }

        return [$this->line, $this->character] <=> [$line, $character];
    }
}
