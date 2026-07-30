<?php

namespace Symfony\Lsp\Feature;

interface RenameProviderInterface
{
    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>|null
     */
    public function prepare(array $params): ?array;

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>|null
     */
    public function rename(array $params): ?array;
}
