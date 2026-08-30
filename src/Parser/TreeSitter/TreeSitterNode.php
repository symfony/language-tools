<?php

namespace Symfony\Lsp\Parser\TreeSitter;

final class TreeSitterNode
{
    /**
     * @param list<int> $children
     */
    public function __construct(
        public readonly string $type,
        public readonly int $startByte,
        public readonly int $endByte,
        public readonly ?int $parent,
        public readonly ?string $field,
        public readonly bool $error,
        public readonly bool $missing,
        public readonly bool $hasError,
        public readonly array $children,
    ) {
    }
}
