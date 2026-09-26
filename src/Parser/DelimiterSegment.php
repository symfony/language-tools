<?php

namespace Symfony\Lsp\Parser;

final class DelimiterSegment
{
    public function __construct(
        public readonly string $text,
        public readonly int $offset,
    ) {
    }
}
