<?php

namespace Symfony\Lsp\Feature\Messenger;

final class MessengerTransport
{
    public function __construct(private readonly string $name, private readonly bool $failure)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isFailure(): bool
    {
        return $this->failure;
    }
}
