<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Index\AbstractProjectIndexRegistry;

/** @extends AbstractProjectIndexRegistry<EventSourceIndex> */
final class EventSourceIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(EventSourceIndex::class);
    }
}
