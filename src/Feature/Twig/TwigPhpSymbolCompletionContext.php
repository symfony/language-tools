<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigPhpSymbolCompletionContext
{
    public function __construct(
        private readonly TwigPhpSymbolCompletionKind $kind,
        private readonly string $prefix,
        private readonly Range $range,
        private readonly ?string $className = null,
    ) {
    }

    public function kind(): TwigPhpSymbolCompletionKind
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

    public function className(): ?string
    {
        return $this->className;
    }
}
