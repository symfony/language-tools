<?php

namespace Symfony\Lsp\Feature\Event;

final class Event
{
    public function __construct(public readonly string $name, public readonly ?string $className)
    {
    }
}
