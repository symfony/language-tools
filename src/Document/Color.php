<?php

namespace Symfony\Lsp\Document;

final class Color
{
    public function __construct(
        public readonly float $red,
        public readonly float $green,
        public readonly float $blue,
        public readonly float $alpha = 1.0,
    ) {
    }
}
