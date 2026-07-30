<?php

namespace Symfony\Lsp\Feature;

interface ReferencesProviderInterface
{
    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function references(array $params): ?array;
}
