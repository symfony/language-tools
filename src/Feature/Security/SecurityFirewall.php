<?php

namespace Symfony\Lsp\Feature\Security;

final class SecurityFirewall
{
    /** @param list<string> $authenticators */
    public function __construct(
        private readonly string $name,
        private readonly ?string $provider,
        private readonly bool $enabled,
        private readonly bool $stateless,
        private readonly bool $lazy,
        private readonly array $authenticators,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isStateless(): bool
    {
        return $this->stateless;
    }

    public function isLazy(): bool
    {
        return $this->lazy;
    }

    /** @return list<string> */
    public function authenticators(): array
    {
        return $this->authenticators;
    }
}
