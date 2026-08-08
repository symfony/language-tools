<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\Range;

final class EventSourceSymbol
{
    public function __construct(
        private readonly string $name,
        private readonly string $uri,
        private readonly Range $range,
        private readonly bool $declaration,
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
}
