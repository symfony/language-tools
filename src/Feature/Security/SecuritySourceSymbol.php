<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Document\Range;

final class SecuritySourceSymbol
{
    public function __construct(
        private readonly SecuritySymbolKind $kind,
        private readonly string $name,
        private readonly string $uri,
        private readonly Range $range,
        private readonly bool $declaration,
    ) {
    }

    public function kind(): SecuritySymbolKind
    {
        return $this->kind;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function range(): Range
    {
        return $this->range;
    }

    public function isDeclaration(): bool
    {
        return $this->declaration;
    }
}
