<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\Range;

final class InvalidEventListenerMethod
{
    public function __construct(
        public readonly string $className,
        public readonly string $method,
        public readonly Range $range,
    ) {
    }
}
