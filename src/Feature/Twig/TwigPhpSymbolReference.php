<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigPhpSymbolReference
{
    public function __construct(
        private readonly string $className,
        private readonly ?string $memberName,
        private readonly string $uri,
        private readonly Range $range,
    ) {
    }

    public function className(): string
    {
        return $this->className;
    }

    public function memberName(): ?string
    {
        return $this->memberName;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function range(): Range
    {
        return $this->range;
    }
}
