<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\DocumentRequest;

interface DiagnosticProviderInterface
{
    public function name(): string;

    /**
     * A provider that does not analyze the document at all reports no
     * diagnostics, which the collector tells apart from an empty result.
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function diagnostics(DocumentRequest $request): ?array;
}
