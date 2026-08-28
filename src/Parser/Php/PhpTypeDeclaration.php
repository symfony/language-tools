<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpTypeDeclaration
{
    public function __construct(
        private readonly string $name,
        private readonly ?string $parentClassName,
        private readonly int $nameStartOffset,
        private readonly int $nameEndOffset,
        private readonly int $startOffset,
        private readonly int $endOffset,
        private readonly PhpTypeKind $kind,
        private readonly string $signature,
        private readonly ?string $description,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function parentClassName(): ?string
    {
        return $this->parentClassName;
    }

    public function nameStartOffset(): int
    {
        return $this->nameStartOffset;
    }

    public function nameEndOffset(): int
    {
        return $this->nameEndOffset;
    }

    public function startOffset(): int
    {
        return $this->startOffset;
    }

    public function endOffset(): int
    {
        return $this->endOffset;
    }

    public function kind(): PhpTypeKind
    {
        return $this->kind;
    }

    public function isClass(): bool
    {
        return PhpTypeKind::Class_ === $this->kind;
    }

    public function isEnum(): bool
    {
        return PhpTypeKind::Enum === $this->kind;
    }

    public function signature(): string
    {
        return $this->signature;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function contains(int $offset): bool
    {
        return $this->isClass() && $offset >= $this->startOffset && $offset <= $this->endOffset;
    }
}
