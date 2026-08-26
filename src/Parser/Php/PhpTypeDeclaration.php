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
        private readonly bool $class,
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

    public function isClass(): bool
    {
        return $this->class;
    }

    public function contains(int $offset): bool
    {
        return $this->class && $offset >= $this->startOffset && $offset <= $this->endOffset;
    }
}
