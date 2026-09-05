<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpLiteralArray
{
    /** @param list<PhpStringLiteral> $keys */
    public function __construct(
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly array $keys,
        public readonly bool $hasUnknownKeys,
    ) {
    }
}
