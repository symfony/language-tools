<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

final class PhpAutowireArgument
{
    public function __construct(
        public readonly ?string $name,
        public readonly int $position,
        public readonly int $valueStartOffset,
        public readonly string $value,
    ) {
    }

    public function cursorOffset(): int
    {
        return $this->valueStartOffset + \strlen($this->value);
    }
}
