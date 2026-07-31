<?php

namespace Symfony\Lsp\Feature;

interface CodeLensProviderInterface
{
    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function codeLenses(array $params): ?array;
}
