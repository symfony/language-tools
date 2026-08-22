<?php

namespace Symfony\Lsp\Parser;

use Symfony\Lsp\Document\Range;

final class QuotedArgument
{
    public function __construct(
        public readonly string $name,
        public readonly int $nameOffset,
        public readonly string $value,
        public readonly int $offset,
        public readonly int $length,
        public readonly Range $range,
    ) {
    }

    public function end(): int
    {
        return $this->offset + $this->length + 1;
    }
}
