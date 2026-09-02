<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpParameter
{
    /** @param list<string> $types */
    public function __construct(
        public readonly string $name,
        public readonly array $types,
        public readonly int $nameStartOffset,
        public readonly int $nameEndOffset,
        public readonly bool $variadic,
    ) {
    }
}
