<?php

namespace Symfony\Lsp\Feature\Messenger;

final class MessengerTransport
{
    public function __construct(public readonly string $name, public readonly bool $failure)
    {
    }
}
