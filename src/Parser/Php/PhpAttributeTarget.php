<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpAttributeTarget
{
    public function __construct(
        private readonly PhpAttributeTargetKind $kind,
        private readonly string $className,
        private readonly ?string $memberName,
        private readonly int $nameStartOffset,
        private readonly int $nameEndOffset,
    ) {
    }

    public function kind(): PhpAttributeTargetKind
    {
        return $this->kind;
    }

    public function className(): string
    {
        return $this->className;
    }

    public function memberName(): ?string
    {
        return $this->memberName;
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
