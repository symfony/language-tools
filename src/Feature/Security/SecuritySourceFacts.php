<?php

namespace Symfony\Lsp\Feature\Security;

final class SecuritySourceFacts
{
    /** @param list<SecuritySourceSymbol> $symbols */
    public function __construct(private readonly string $uri, private readonly array $symbols)
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<SecuritySourceSymbol> */
    public function symbols(): array
    {
        return $this->symbols;
    }
}
