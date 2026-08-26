<?php

namespace Symfony\Lsp\Feature\Console;

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
            $key = strtolower(ltrim($command->className(), '\\'));
            if (!isset($this->commands[$key])) {
                $this->commands[$key] = $command;
                continue;
            }
            $existing = $this->commands[$key];
            $arguments = array_values(array_unique([...$existing->arguments(), ...$command->arguments()]));
            $options = array_values(array_unique([...$existing->options(), ...$command->options()]));
            sort($arguments);
            sort($options);
            $this->commands[$key] = new ConsoleCommandMetadata(
                $existing->className(),
                $existing->file() ?? $command->file(),
                $arguments,
                $options,
                $existing->isComplete()
                    && $command->isComplete()
                    && $existing->arguments() === $command->arguments()
                    && $existing->options() === $command->options(),
            );
        }
        ksort($this->commands);
        $this->complete = $complete;
    }

    public function command(string $className): ?ConsoleCommandMetadata
    {
        return $this->commands[strtolower(ltrim($className, '\\'))] ?? null;
    }

    /** @return list<ConsoleCommandMetadata> */
    public function commands(): array
    {
        return array_values($this->commands);
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
