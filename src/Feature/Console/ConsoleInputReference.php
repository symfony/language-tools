<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Document\Range;

final class ConsoleInputReference
{
    public function __construct(
        public readonly ConsoleInputKind $kind,
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly string $commandClass,
    ) {
    }
}
