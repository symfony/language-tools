<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

final class Service
{
    /**
     * @param list<string> $tags
     * @param list<string> $autowiringTypes
     * @param list<string> $decorationStack
     */
    public function __construct(
        private readonly string $id,
        private readonly ?string $className,
        private readonly ?string $alias,
        private readonly ?bool $public,
        private readonly ?bool $lazy,
        private readonly ?string $deprecation,
        private readonly array $tags,
        private readonly ?string $decorates,
        private readonly array $autowiringTypes,
        private readonly array $decorationStack = [],
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function className(): ?string
    {
        return $this->className;
    }

    public function alias(): ?string
    {
        return $this->alias;
    }

    public function isPublic(): ?bool
    {
        return $this->public;
    }

    public function isLazy(): ?bool
    {
        return $this->lazy;
    }

    public function deprecation(): ?string
    {
        return $this->deprecation;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->tags;
    }

    public function decorates(): ?string
    {
        return $this->decorates;
    }

    /** @return list<string> */
    public function autowiringTypes(): array
    {
        return $this->autowiringTypes;
    }

    /** @return list<string> */
    public function decorationStack(): array
    {
        return $this->decorationStack;
    }
}
