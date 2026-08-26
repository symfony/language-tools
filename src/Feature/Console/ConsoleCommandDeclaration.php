<?php

namespace Symfony\Lsp\Feature\Console;

final class ConsoleCommandDeclaration
{
    /**
     * @param list<string> $traits
     * @param list<string> $arguments
     * @param list<string> $options
     */
    public function __construct(
        private readonly string $className,
        private readonly ?string $parentClassName,
        private readonly array $traits,
        private readonly array $arguments,
        private readonly array $options,
        private readonly bool $command,
        private readonly bool $complete,
    ) {
    }

    public function className(): string
    {
        return $this->className;
    }

    public function parentClassName(): ?string
    {
        return $this->parentClassName;
    }

    /** @return list<string> */
    public function traits(): array
    {
        return $this->traits;
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
