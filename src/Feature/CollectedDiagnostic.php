<?php

namespace Symfony\Lsp\Feature;

final class CollectedDiagnostic
{
    /** @param array<array-key, mixed> $diagnostic */
    public function __construct(
        public readonly string $provider,
        public readonly array $diagnostic,
    ) {
    }
}
