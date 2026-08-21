<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Index\AbstractProjectIndexRegistry;

/** @extends AbstractProjectIndexRegistry<EventIndex> */
final class EventIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(EventIndex::class);
    }
}
