<?php

namespace Symfony\Lsp\Document;

final class Position
{
    public function __construct(
        private readonly int $line,
        private readonly int $character,
    ) {
        if ($line < 0 || $character < 0) {
            throw new \InvalidArgumentException('Document positions cannot be negative.');
        }
    }

    public function line(): int
    {
        return $this->line;
    }

    public function character(): int
    {
        return $this->character;
    }
}
