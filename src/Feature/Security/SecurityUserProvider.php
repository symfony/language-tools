<?php

namespace Symfony\Lsp\Feature\Security;

final class SecurityUserProvider
{
    public function __construct(public readonly string $name, public readonly string $type)
    {
    }
}
