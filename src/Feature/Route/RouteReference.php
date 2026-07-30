<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Range;

final class RouteReference
{
    public function __construct(
        private readonly string $name,
        private readonly Range $range,
    ) {
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
