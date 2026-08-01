<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpStringLiteral
{
    public function __construct(
        private readonly string $value,
        private readonly int $startOffset,
        private readonly int $endOffset,
    ) {
    }

    public function value(): string
    {
        return $this->value;
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
