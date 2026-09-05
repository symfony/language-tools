<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpTypeDeclaration
{
    /**
     * @param list<string> $traitNames
     * @param list<string> $interfaceNames
     */
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
        public readonly array $traitNames = [],
        public readonly array $interfaceNames = [],
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
