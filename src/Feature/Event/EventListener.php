<?php

namespace Symfony\Lsp\Feature\Event;

final class EventListener
{
    public function __construct(
        private readonly string $event,
        private readonly string $className,
        private readonly string $method,
        private readonly int $priority,
    ) {
    }

    public function event(): string
    {
        return $this->event;
    }

    public function className(): string
    {
        return $this->className;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function priority(): int
    {
        return $this->priority;
    }
}
