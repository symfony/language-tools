<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpMethodReceiver
{
    public function __construct(
        private readonly PhpMethodReceiverKind $kind,
        private readonly ?string $name,
        private readonly int $startOffset,
        private readonly int $endOffset,
    ) {
    }

    public function kind(): PhpMethodReceiverKind
    {
        return $this->kind;
    }

    public function name(): ?string
    {
        return $this->name;
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
