<?php

namespace App\Controller;

use App\Message\Ping;
use Symfony\Component\Messenger\MessageBusInterface;

final class PingController
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(): void
    {
        $this->bus->dispatch(new Ping());
    }
}
