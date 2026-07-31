<?php

namespace App\MessageHandler;

use App\Message\Ping;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class PingHandler
{
    public function __invoke(Ping $message): void
    {
    }
}
