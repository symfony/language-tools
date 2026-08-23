<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpMethodDeclaration
{
    /** @param list<PhpAttribute> $attributes */
    public function __construct(
        private readonly string $className,
        private readonly string $name,
        private readonly int $nameStartOffset,
        private readonly int $nameEndOffset,
        private readonly string $signature,
        private readonly ?string $description,
        private readonly array $attributes = [],
        private readonly ?string $firstParameterType = null,
        private readonly bool $firstParameterVariadic = false,
        private readonly bool $variadic = false,
        private readonly bool $public = true,
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

    /** @return list<PhpAttribute> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function firstParameterType(): ?string
    {
        return $this->firstParameterType;
    }

    public function isFirstParameterVariadic(): bool
    {
        return $this->firstParameterVariadic;
    }

    public function isVariadic(): bool
    {
        return $this->variadic;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }
}
