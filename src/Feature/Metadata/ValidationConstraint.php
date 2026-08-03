<?php

namespace Symfony\Lsp\Feature\Metadata;

final class ValidationConstraint
{
    /** @param list<string> $options */
    public function __construct(
        private readonly string $name,
        private readonly string $className,
        private readonly array $options,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function className(): string
    {
        return $this->className;
    }

    /** @return list<string> */
    public function options(): array
    {
        return $this->options;
    }
}
