<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\Range;

final class MetadataSourceSymbol
{
    public function __construct(
        private readonly MetadataSymbolKind $kind,
        private readonly string $name,
        private readonly string $uri,
        private readonly Range $range,
        private readonly bool $declaration,
        private readonly ?string $signature = null,
        private readonly ?string $description = null,
    ) {
    }

    public function kind(): MetadataSymbolKind
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

    public function signature(): ?string
    {
        return $this->signature;
    }

    public function description(): ?string
    {
        return $this->description;
    }
}
