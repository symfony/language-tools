<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpDiagnostic
{
    public function __construct(
        private readonly string $message,
        private readonly int $startOffset,
        private readonly int $endOffset,
    ) {
    }

    public function message(): string
    {
        return $this->message;
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
