<?php

namespace Symfony\Lsp\Parser\Twig;

final class TwigStringLiteral
{
    public function __construct(
        public readonly string $raw,
        public readonly string $value,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly string $quote,
    ) {
    }
}
