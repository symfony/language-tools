<?php

namespace Symfony\Lsp\Feature;

interface CodeActionProviderInterface
{
    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function actions(array $params): ?array;
}
