<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Document\Range;

final class ConsoleInputReference
{
    public function __construct(
        private readonly ConsoleInputKind $kind,
        private readonly string $name,
        private readonly string $uri,
        private readonly Range $range,
        private readonly string $commandClass,
    ) {
    }

    public function kind(): ConsoleInputKind
    {
        return $this->kind;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function range(): Range
    {
        return $this->range;
    }

    public function commandClass(): string
    {
        return $this->commandClass;
    }
}
