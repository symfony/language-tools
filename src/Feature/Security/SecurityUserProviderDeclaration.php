<?php

namespace Symfony\Lsp\Feature\Security;

final class SecurityUserProviderDeclaration
{
    public function __construct(public readonly string $name, public readonly string $type)
    {
    }
}
