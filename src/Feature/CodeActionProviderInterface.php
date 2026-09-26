<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\CodeActionRequest;

interface CodeActionProviderInterface
{
    /** @return list<array<array-key, mixed>> */
    public function actions(CodeActionRequest $request): array;
}
