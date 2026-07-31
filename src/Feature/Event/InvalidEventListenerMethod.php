<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\Range;

final class InvalidEventListenerMethod
{
    public function __construct(
        private readonly string $className,
        private readonly string $method,
        private readonly Range $range,
    ) {
    }

    public function className(): string
    {
        return $this->className;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function range(): Range
    {
        return $this->range;
    }
}
