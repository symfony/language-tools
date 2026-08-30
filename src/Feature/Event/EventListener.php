<?php

namespace Symfony\Lsp\Feature\Event;

final class EventListener
{
    public function __construct(
        public readonly string $event,
        public readonly string $className,
        public readonly string $method,
        public readonly int $priority,
    ) {
    }
}
