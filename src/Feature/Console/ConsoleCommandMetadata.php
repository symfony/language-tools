<?php

namespace Symfony\Lsp\Feature\Console;

final class ConsoleCommandMetadata
{
    /**
     * @param list<string> $arguments
     * @param list<string> $options
     */
    public function __construct(
        private readonly string $className,
        private readonly ?string $file,
        private readonly array $arguments,
        private readonly array $options,
        private readonly bool $complete,
    ) {
    }

    public function className(): string
    {
        return $this->className;
    }

    public function file(): ?string
    {
        return $this->file;
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

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
