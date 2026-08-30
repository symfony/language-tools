<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\Range;

final class EventSourceSymbol
{
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly bool $declaration,
    ) {
    }
}
