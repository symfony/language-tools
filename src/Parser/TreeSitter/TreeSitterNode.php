<?php

namespace Symfony\Lsp\Parser\TreeSitter;

final class TreeSitterNode
{
    /**
     * @param list<int> $children
     */
    public function __construct(
        private readonly string $type,
        private readonly int $startByte,
        private readonly int $endByte,
        private readonly ?int $parent,
        private readonly ?string $field,
        private readonly bool $error,
        private readonly bool $missing,
        private readonly bool $hasError,
        private readonly array $children,
    ) {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function startByte(): int
    {
        return $this->startByte;
    }

    public function endByte(): int
    {
        return $this->endByte;
    }

    public function parent(): ?int
    {
        return $this->parent;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    public function isError(): bool
    {
        return $this->error;
    }

    public function isMissing(): bool
    {
        return $this->missing;
    }

    public function hasError(): bool
    {
        return $this->hasError;
    }

    /** @return list<int> */
    public function children(): array
    {
        return $this->children;
    }
}
