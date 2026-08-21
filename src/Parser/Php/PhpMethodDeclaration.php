<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpMethodDeclaration
{
    public function __construct(
        private readonly string $className,
        private readonly string $name,
        private readonly int $nameStartOffset,
        private readonly int $nameEndOffset,
        private readonly string $signature,
        private readonly ?string $description,
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
}
