<?php

namespace Symfony\Lsp\Parser;

final class DelimiterString
{
    public function __construct(
        public readonly string $quote,
        public readonly int $contentOffset,
    ) {
    }
}
