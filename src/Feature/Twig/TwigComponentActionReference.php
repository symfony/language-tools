<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigComponentActionReference
{
    public function __construct(
        private readonly string $component,
        private readonly string $action,
        private readonly string $uri,
        private readonly Range $range,
    ) {
    }

    public function component(): string
    {
        return $this->component;
    }

    public function action(): string
    {
        return $this->action;
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
