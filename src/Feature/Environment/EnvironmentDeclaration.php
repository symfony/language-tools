<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Document\Range;

final class EnvironmentDeclaration
{
    public function __construct(
        private readonly string $name,
        private readonly string $uri,
        private readonly Range $range,
        private readonly bool $hasDefault,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function range(): Range
    {
        return $this->range;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }
}
