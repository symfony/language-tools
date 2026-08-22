<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class Probe
{
    public function __construct(
        public readonly string $category,
        public readonly string $path,
        public readonly string $contents,
        public readonly string $value,
        public readonly int $line,
        public readonly int $character,
    ) {
    }
}
