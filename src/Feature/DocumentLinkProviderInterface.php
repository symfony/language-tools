<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\DocumentRequest;

interface DocumentLinkProviderInterface
{
    /** @return list<array<array-key, mixed>> */
    public function links(DocumentRequest $request): array;
}
