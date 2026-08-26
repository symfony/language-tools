<?php

namespace Symfony\Lsp\Feature\Console;

final class ConsoleEffectiveDefinition
{
    /**
     * @param list<string> $arguments
     * @param list<string> $options
     */
    public function __construct(
        private readonly array $arguments,
        private readonly array $options,
        private readonly bool $command,
        private readonly bool $complete,
    ) {
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /** @return list<string> */
    public function options(): array
    {
        return $this->options;
    }

    public function isCommand(): bool
    {
        return $this->command;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
