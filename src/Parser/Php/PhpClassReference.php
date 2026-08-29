<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpClassReference
{
    public function __construct(
        private readonly string $className,
        private readonly int $startOffset,
        private readonly int $endOffset,
    ) {
    }

    public function className(): string
    {
        return $this->className;
    }

    public function startOffset(): int
    {
        return $this->startOffset;
    }

    public function endOffset(): int
    {
        return $this->endOffset;
    }
}
