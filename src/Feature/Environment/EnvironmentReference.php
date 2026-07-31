<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Document\Range;

final class EnvironmentReference
{
    /** @param list<string> $processors */
    public function __construct(
        private readonly string $name,
        private readonly string $uri,
        private readonly Range $range,
        private readonly array $processors,
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

    /** @return list<string> */
    public function processors(): array
    {
        return $this->processors;
    }
}
