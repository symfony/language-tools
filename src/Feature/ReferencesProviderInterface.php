<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\ReferencesRequest;

interface ReferencesProviderInterface
{
    /** @return list<array<array-key, mixed>> */
    public function references(ReferencesRequest $request): array;
}
