<?php

namespace Symfony\Lsp\Feature;

interface DefinitionProviderInterface
{
    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function definition(array $params): ?array;
}
