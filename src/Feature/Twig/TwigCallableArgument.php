<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigCallableArgument
{
    public function __construct(
        public readonly string $text,
        public readonly int $offset,
        public readonly ?string $name = null,
        public readonly ?int $nameOffset = null,
    ) {
    }
}
