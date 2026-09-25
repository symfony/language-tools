<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Index\ClassNameKey;

final class ConsoleIndex
{
    /** @var array<string, ConsoleCommandMetadata> */
    private array $commands = [];
    private bool $complete = false;

    /** @param list<ConsoleCommandMetadata> $commands */
    public function replace(array $commands, bool $complete): void
    {
        $this->commands = [];
        foreach ($commands as $command) {
            $this->commands[ClassNameKey::from($command->className)] = $command;
        }
        $this->complete = $complete;
    }

    public function command(string $className): ?ConsoleCommandMetadata
    {
        return $this->commands[ClassNameKey::from($className)] ?? null;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
