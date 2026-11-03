<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\ColorPresentationRequest;
use Symfony\Lsp\Protocol\DocumentRequest;

interface DocumentColorProviderInterface
{
    /** @return list<array<array-key, mixed>> */
    public function colors(DocumentRequest $request): array;

    /** @return list<array<array-key, mixed>> */
    public function presentations(ColorPresentationRequest $request): array;
}
