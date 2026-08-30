<?php

namespace Symfony\Lsp\Feature\Security;

final class SecurityRole
{
    /** @param list<string> $inheritedRoles */
    public function __construct(public readonly string $name, public readonly array $inheritedRoles)
    {
    }
}
