<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Range;

final class ServiceDeclaration
{
    /** @param list<string> $tags */
    public function __construct(
        private readonly string $id,
        private readonly string $uri,
        private readonly Range $range,
        private readonly ?string $className = null,
        private readonly ?string $alias = null,
        private readonly ?string $decorates = null,
        private readonly array $tags = [],
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function range(): Range
    {
        return $this->range;
    }

    public function className(): ?string
    {
        return $this->className;
    }

    public function alias(): ?string
    {
        return $this->alias;
    }

    public function decorates(): ?string
    {
        return $this->decorates;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->tags;
    }
}
