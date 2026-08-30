<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Index\SourceFactsInterface;

final class SecuritySourceFacts implements SourceFactsInterface
{
    /** @param list<SecuritySourceSymbol> $symbols */
    public function __construct(public readonly string $uri, public readonly array $symbols)
    {
    }

    public function isEmpty(): bool
    {
        return [] === $this->symbols;
    }
}
