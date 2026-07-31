<?php

namespace Symfony\Lsp\Feature\Messenger;

final class MessengerMessage
{
    /** @param list<string> $transports */
    public function __construct(private readonly string $className, private readonly array $transports)
    {
    }

    public function className(): string
    {
        return $this->className;
    }

    /** @return list<string> */
    public function transports(): array
    {
        return $this->transports;
    }
}
