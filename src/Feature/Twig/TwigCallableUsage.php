<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigCallableUsage
{
    public function __construct(
        private readonly TwigCallableKind $kind,
        private readonly string $name,
        private readonly string $uri,
        private readonly Range $range,
    ) {
    }

    public function kind(): TwigCallableKind
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
}
