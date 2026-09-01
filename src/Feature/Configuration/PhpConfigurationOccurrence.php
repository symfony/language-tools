<?php

namespace Symfony\Lsp\Feature\Configuration;

final class PhpConfigurationOccurrence
{
    /** @param list<string> $path */
    public function __construct(
        public readonly array $path,
        public readonly string $argument,
        public readonly int $startOffset,
        public readonly int $endOffset,
    ) {
    }
}
