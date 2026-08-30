<?php

namespace Symfony\Lsp\Document;

final class Position
{
    public function __construct(
        public readonly int $line,
        public readonly int $character,
    ) {
        if ($line < 0 || $character < 0) {
            throw new \InvalidArgumentException('Document positions cannot be negative.');
        }
    }
}
