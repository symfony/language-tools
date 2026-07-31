<?php

namespace Symfony\Lsp\Feature\Messenger;

final class MessengerHandler
{
    public function __construct(
        private readonly string $message,
        private readonly string $bus,
        private readonly string $service,
        private readonly string $className,
        private readonly string $method,
        private readonly int $priority,
        private readonly ?string $fromTransport,
    ) {
    }

    public function message(): string
    {
        return $this->message;
    }

    public function bus(): string
    {
        return $this->bus;
    }

    public function service(): string
    {
        return $this->service;
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

    public function fromTransport(): ?string
    {
        return $this->fromTransport;
    }
}
