<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpTypedVariable
{
    /** @param list<string> $types */
    public function __construct(
        private readonly string $name,
        private readonly array $types,
        private readonly PhpTypedVariableKind $kind,
        private readonly ?string $className,
        private readonly ?string $methodName,
        private readonly ?int $scopeStartOffset,
        private readonly int $nameStartOffset,
        private readonly int $nameEndOffset,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function types(): array
    {
        return $this->types;
    }

    public function kind(): PhpTypedVariableKind
    {
        return $this->kind;
    }

    public function className(): ?string
    {
        return $this->className;
    }

    public function methodName(): ?string
    {
        return $this->methodName;
    }

    public function scopeStartOffset(): ?int
    {
        return $this->scopeStartOffset;
    }

    public function nameStartOffset(): int
    {
        return $this->nameStartOffset;
    }

    public function nameEndOffset(): int
    {
        return $this->nameEndOffset;
    }
}
