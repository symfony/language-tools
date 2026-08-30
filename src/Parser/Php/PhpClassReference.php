<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpClassReference
{
    public function __construct(
        public readonly string $className,
        public readonly int $startOffset,
        public readonly int $endOffset,
    ) {
    }
}
