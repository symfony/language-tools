<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Document\Range;

final class ConsoleCompletionContext
{
    public function __construct(
        public readonly ConsoleInputKind $kind,
        public readonly string $prefix,
        public readonly Range $range,
        public readonly string $commandClass,
    ) {
    }
}
