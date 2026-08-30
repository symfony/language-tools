<?php

namespace Symfony\Lsp\Feature\Messenger;

final class MessengerBus
{
    public function __construct(public readonly string $name, public readonly bool $default)
    {
    }
}
