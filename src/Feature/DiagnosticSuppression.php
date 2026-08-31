<?php

namespace Symfony\Lsp\Feature;

final class DiagnosticSuppression
{
    /** @param non-empty-list<string> $codes */
    public function __construct(
        public readonly int $targetLine,
        public readonly array $codes,
    ) {
    }
}
