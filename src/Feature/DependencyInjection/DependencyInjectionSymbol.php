<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Range;

final class DependencyInjectionSymbol
{
    public function __construct(
        private readonly DependencyInjectionSymbolKind $kind,
        private readonly string $name,
        private readonly Range $range,
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

    public function range(): Range
    {
        return $this->range;
    }
}
