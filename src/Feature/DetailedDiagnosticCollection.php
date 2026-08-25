<?php

namespace Symfony\Lsp\Feature;

final class DetailedDiagnosticCollection
{
    /**
     * @param list<CollectedDiagnostic>       $diagnostics
     * @param list<DiagnosticProviderFailure> $failures
     */
    public function __construct(
        public readonly bool $matched,
        public readonly array $diagnostics,
        public readonly array $failures,
    ) {
    }
}
