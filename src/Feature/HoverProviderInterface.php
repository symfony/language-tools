<?php

namespace Symfony\Lsp\Feature;

interface HoverProviderInterface
{
    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>|null
     */
    public function hover(array $params): ?array;
}
