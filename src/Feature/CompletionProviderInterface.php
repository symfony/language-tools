<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\PositionedRequest;

interface CompletionProviderInterface
{
    /** @return list<array<array-key, mixed>> */
    public function complete(PositionedRequest $request): array;
}
