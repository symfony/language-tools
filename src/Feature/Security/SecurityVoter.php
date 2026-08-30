<?php

namespace Symfony\Lsp\Feature\Security;

final class SecurityVoter
{
    public function __construct(public readonly string $className)
    {
    }
}
