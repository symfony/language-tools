<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpStringLiteral
{
    public function __construct(
        public readonly string $value,
        public readonly int $startOffset,
        public readonly int $endOffset,
    ) {
    }
}
