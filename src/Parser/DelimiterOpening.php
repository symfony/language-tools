<?php

namespace Symfony\Lsp\Parser;

final class DelimiterOpening
{
    public function __construct(
        public readonly string $delimiter,
        public readonly int $offset,
    ) {
    }
}
