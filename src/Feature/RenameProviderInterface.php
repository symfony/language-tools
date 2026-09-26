<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\RenameRequest;

interface RenameProviderInterface
{
    /** @return array<array-key, mixed>|null */
    public function prepare(PositionedRequest $request): ?array;

    /** @return array<array-key, mixed>|null */
    public function rename(RenameRequest $request): ?array;
}
