<?php

namespace Symfony\Lsp\Feature;

interface DiagnosticProviderInterface
{
    public function name(): string;

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function diagnostics(array $params): ?array;
}
