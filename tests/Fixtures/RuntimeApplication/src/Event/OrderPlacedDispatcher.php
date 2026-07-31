<?php

namespace App\Event;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class OrderPlacedDispatcher
{
    public function __construct(private EventDispatcherInterface $dispatcher)
    {
    }

    public function dispatch(): void
    {
        $this->dispatcher->dispatch(new OrderPlaced());
    }
}
