<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Mimics listeners that need an external service (such as a database
 * connection) to be constructed: loading event metadata must never
 * instantiate listeners.
 */
#[AsEventListener(event: 'legacy.order_placed')]
final class AuditOrder
{
    public function __construct()
    {
        throw new \RuntimeException('This listener requires an external service to be constructed.');
    }

    public function __invoke(object $event): void
    {
    }
}
