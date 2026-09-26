<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpLiteralArray
{
    /** @var list<PhpStringLiteral> */
    public array $keys {
        get => array_values(array_filter(array_map(static fn (PhpLiteralArrayEntry $entry): ?PhpStringLiteral => $entry->key, $this->entries)));
    }

    /** @param list<PhpLiteralArrayEntry> $entries */
    public function __construct(
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly array $entries,
        public readonly bool $hasUnknownKeys,
        public readonly bool $complete,
    ) {
    }
}
