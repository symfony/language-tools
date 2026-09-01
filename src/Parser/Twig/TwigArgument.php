<?php

namespace Symfony\Lsp\Parser\Twig;

final class TwigArgument
{
    public function __construct(
        public readonly string $text,
        public readonly int $offset,
        public readonly int $valueOffset,
        public readonly ?string $name = null,
        public readonly ?int $nameOffset = null,
    ) {
    }
}
