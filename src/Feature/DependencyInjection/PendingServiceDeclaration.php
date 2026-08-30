<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Range;

final class PendingServiceDeclaration
{
    public ?string $className = null;
    public ?string $alias = null;
    public ?string $decorates = null;

    /** @var list<string> */
    public array $tags = [];

    public function __construct(
        public readonly string $id,
        public readonly Range $range,
    ) {
    }

    public function declaration(string $uri): ServiceDeclaration
    {
        return new ServiceDeclaration(
            $this->id,
            $uri,
            $this->range,
            $this->className ?? (str_contains($this->id, '\\') ? ltrim($this->id, '\\') : null),
            $this->alias,
            $this->decorates,
            array_values(array_unique($this->tags)),
        );
    }
}
