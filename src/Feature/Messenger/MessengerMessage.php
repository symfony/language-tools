<?php

namespace Symfony\Lsp\Feature\Messenger;

final class MessengerMessage
{
    /** @param list<string> $transports */
    public function __construct(public readonly string $className, public readonly array $transports)
    {
    }
}
