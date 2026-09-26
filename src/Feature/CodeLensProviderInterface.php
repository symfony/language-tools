<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\DocumentRequest;

interface CodeLensProviderInterface
{
    /** @return list<array<array-key, mixed>> */
    public function codeLenses(DocumentRequest $request): array;
}
