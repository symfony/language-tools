<?php

namespace Symfony\Lsp\Feature\Console;

final class ConsoleCommandMetadata
{
    /**
     * @param list<string> $arguments
     * @param list<string> $options
     */
    public function __construct(
        public readonly string $className,
        public readonly ?string $file,
        public readonly array $arguments,
        public readonly array $options,
        public readonly bool $complete,
    ) {
    }
}
