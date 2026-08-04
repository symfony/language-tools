<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class LiveComponentEvent
{
    public function __construct(
        private readonly string $name,
        private readonly string $uri,
        private readonly Range $range,
        private readonly bool $declaration,
        private readonly ?string $component = null,
        private readonly ?string $action = null,
    ) {
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

    public function component(): ?string
    {
        return $this->component;
    }

    public function action(): ?string
    {
        return $this->action;
    }
}
