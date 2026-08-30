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
        public readonly string $className,
        public readonly ?string $parentClassName,
        public readonly array $traits,
        public readonly array $arguments,
        public readonly array $options,
        public readonly bool $command,
        public readonly bool $complete,
    ) {
    }
}
