<?php

namespace Symfony\Lsp\Feature\Security;

final class SecurityRole
{
    /** @param list<string> $inheritedRoles */
    public function __construct(private readonly string $name, private readonly array $inheritedRoles)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function inheritedRoles(): array
    {
        return $this->inheritedRoles;
    }
}
