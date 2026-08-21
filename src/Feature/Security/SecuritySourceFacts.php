<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Index\SourceFactsInterface;

final class SecuritySourceFacts implements SourceFactsInterface
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

    public function isEmpty(): bool
    {
        return [] === $this->symbols;
    }
}
