<?php

namespace Symfony\Lsp\Feature;

interface CompletionProviderInterface
{
    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function complete(array $params): ?array;
}
