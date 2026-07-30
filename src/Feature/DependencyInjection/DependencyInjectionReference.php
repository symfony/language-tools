<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Range;

final class DependencyInjectionReference
{
    public function __construct(
        private readonly DependencyInjectionSymbolKind $kind,
        private readonly string $name,
        private readonly string $uri,
        private readonly Range $range,
        private readonly bool $optional = false,
    ) {
    }

    public function kind(): DependencyInjectionSymbolKind
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

    public function isOptional(): bool
    {
        return $this->optional;
    }
}
