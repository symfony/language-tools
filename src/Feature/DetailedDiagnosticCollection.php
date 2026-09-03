<?php

namespace Symfony\Lsp\Feature;

final class DetailedDiagnosticCollection
{
    /**
     * @param list<CollectedDiagnostic>       $diagnostics
     * @param list<DiagnosticProviderFailure> $failures
     * @param array<string, float>            $providerNanoseconds
     */
    public function __construct(
        public readonly bool $matched,
        public readonly array $diagnostics,
        public readonly array $failures,
        public readonly array $providerNanoseconds = [],
    ) {
    }
}
