<?php

namespace Symfony\Lsp\Runtime;

final class RuntimeSnapshot
{
    /** @param array<array-key, mixed> $snapshot */
    public function __construct(
        public readonly array $snapshot,
        public readonly string $lastSuccessfulAt,
    ) {
    }
}
