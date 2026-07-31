<?php

namespace Symfony\Lsp\Feature\Messenger;

final class MessengerBus
{
    public function __construct(private readonly string $name, private readonly bool $default)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isDefault(): bool
    {
        return $this->default;
    }
}
