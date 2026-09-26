<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\PositionedRequest;

interface DefinitionProviderInterface
{
    /** @return list<array<array-key, mixed>> */
    public function definition(PositionedRequest $request): array;
}
