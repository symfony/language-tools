<?php

namespace Symfony\Lsp\Runtime;

final readonly class RuntimeSnapshot
{
    /** @param array<array-key, mixed> $snapshot */
    public function __construct(
        public array $snapshot,
        public string $lastSuccessfulAt,
    ) {
    }
}
