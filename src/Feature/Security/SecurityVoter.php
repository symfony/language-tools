<?php

namespace Symfony\Lsp\Feature\Security;

final class SecurityVoter
{
    public function __construct(private readonly string $className)
    {
    }

    public function className(): string
    {
        return $this->className;
    }
}
