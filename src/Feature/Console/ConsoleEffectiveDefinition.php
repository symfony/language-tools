<?php

namespace Symfony\Lsp\Feature\Console;

final class ConsoleEffectiveDefinition
{
    /**
     * @param list<string> $arguments
     * @param list<string> $options
     */
    public function __construct(
        public readonly array $arguments,
        public readonly array $options,
        public readonly bool $command,
        public readonly bool $complete,
    ) {
    }
}
