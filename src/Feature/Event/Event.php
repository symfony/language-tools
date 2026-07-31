<?php

namespace Symfony\Lsp\Feature\Event;

final class Event
{
    public function __construct(private readonly string $name, private readonly ?string $className)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function className(): ?string
    {
        return $this->className;
    }
}
