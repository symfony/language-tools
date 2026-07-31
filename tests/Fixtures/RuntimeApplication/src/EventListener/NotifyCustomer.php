<?php

namespace App\EventListener;

use App\Event\OrderPlaced;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class NotifyCustomer
{
    #[AsEventListener(priority: 10)]
    public function onOrderPlaced(OrderPlaced $event): void
    {
    }
}
