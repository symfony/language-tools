<?php

namespace Symfony\Lsp\Feature\Security;

final class SecurityUserProvider
{
    public function __construct(private readonly string $name, private readonly string $type)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }
}
