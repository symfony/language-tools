<?php

namespace Symfony\Lsp\Feature\Security;

final class SecurityFirewall
{
    /** @param list<string> $authenticators */
    public function __construct(
        public readonly string $name,
        public readonly ?string $provider,
        public readonly bool $enabled,
        public readonly bool $stateless,
        public readonly bool $lazy,
        public readonly array $authenticators,
    ) {
    }
}
