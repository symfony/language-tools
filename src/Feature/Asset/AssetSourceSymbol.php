<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Document\Range;

final class AssetSourceSymbol
{
    public function __construct(
        private readonly AssetSymbolKind $kind,
        private readonly string $name,
        private readonly string $uri,
        private readonly Range $range,
        private readonly bool $declaration,
    ) {
    }

    public function kind(): AssetSymbolKind
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
