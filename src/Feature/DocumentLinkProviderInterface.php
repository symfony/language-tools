<?php

namespace Symfony\Lsp\Feature;

interface DocumentLinkProviderInterface
{
    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function links(array $params): ?array;
}
