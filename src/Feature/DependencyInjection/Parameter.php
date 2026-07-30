<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

final class Parameter
{
    public function __construct(
        private readonly string $name,
        private readonly ?string $deprecation,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function deprecation(): ?string
    {
        return $this->deprecation;
    }
}
