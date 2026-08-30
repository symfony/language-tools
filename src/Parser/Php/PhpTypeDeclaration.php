<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpTypeDeclaration
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $parentClassName,
        public readonly int $nameStartOffset,
        public readonly int $nameEndOffset,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly PhpTypeKind $kind,
        public readonly string $signature,
        public readonly ?string $description,
    ) {
    }

    public function isClass(): bool
    {
        return PhpTypeKind::Class_ === $this->kind;
    }

    public function isEnum(): bool
    {
        return PhpTypeKind::Enum === $this->kind;
    }

    public function contains(int $offset): bool
    {
        return $this->isClass() && $offset >= $this->startOffset && $offset <= $this->endOffset;
    }
}
