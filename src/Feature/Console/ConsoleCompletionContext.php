<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Document\Range;

final class ConsoleCompletionContext
{
    public function __construct(
        private readonly ConsoleInputKind $kind,
        private readonly string $prefix,
        private readonly Range $range,
        private readonly string $commandClass,
    ) {
    }

    public function kind(): ConsoleInputKind
    {
        return $this->kind;
    }

    public function prefix(): string
    {
        return $this->prefix;
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
