<?php

namespace Symfony\Lsp\Document;

final class Range
{
    public function __construct(
        public readonly Position $start,
        public readonly Position $end,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->start->line === $other->start->line
            && $this->start->character === $other->start->character
            && $this->end->line === $other->end->line
            && $this->end->character === $other->end->character;
    }

    public function containsPosition(Position $position): bool
    {
        $atOrAfterStart = $position->line > $this->start->line
            || ($position->line === $this->start->line && $position->character >= $this->start->character);
        $atOrBeforeEnd = $position->line < $this->end->line
            || ($position->line === $this->end->line && $position->character <= $this->end->character);

        return $atOrAfterStart && $atOrBeforeEnd;
    }
}
