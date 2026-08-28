<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpPropertyDeclaration
{
    /** @param list<string> $types */
    public function __construct(
        private readonly string $className,
        private readonly string $name,
        private readonly int $nameStartOffset,
        private readonly int $nameEndOffset,
        private readonly string $signature,
        private readonly ?string $description,
        private readonly array $types,
        private readonly string $visibility,
        private readonly bool $promoted,
    ) {
    }

    public function className(): string
    {
        return $this->className;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function nameStartOffset(): int
    {
        return $this->nameStartOffset;
    }

    public function nameEndOffset(): int
    {
        return $this->nameEndOffset;
    }

    public function signature(): string
    {
        return $this->signature;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /** @return list<string> */
    public function types(): array
    {
        return $this->types;
    }

    public function visibility(): string
    {
        return $this->visibility;
    }

    public function isPublic(): bool
    {
        return 'public' === $this->visibility;
    }

    public function isPromoted(): bool
    {
        return $this->promoted;
    }
}
