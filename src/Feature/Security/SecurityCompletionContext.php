<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Document\Range;

final class SecurityCompletionContext
{
    public function __construct(
        private readonly SecuritySymbolKind $kind,
        private readonly string $prefix,
        private readonly Range $range,
    ) {
    }

    public function kind(): SecuritySymbolKind
    {
        return $this->kind;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function range(): Range
    {
        return $this->range;
    }
}
