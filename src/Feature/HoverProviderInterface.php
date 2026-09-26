<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\PositionedRequest;

interface HoverProviderInterface
{
    /** @return array<array-key, mixed>|null */
    public function hover(PositionedRequest $request): ?array;
}
