<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpMethodCall
{
    /**
     * @param list<PhpArgument> $arguments
     */
    public function __construct(
        private readonly string $receiver,
        private readonly PhpMethodReceiver $receiverContext,
        private readonly string $method,
        private readonly int $startOffset,
        private readonly int $endOffset,
        private readonly int $methodStartOffset,
        private readonly int $methodEndOffset,
        private readonly array $arguments,
        private readonly ?string $className,
        private readonly ?string $enclosingMethod,
        private readonly ?int $scopeStartOffset,
    ) {
    }

    public function receiver(): string
    {
        return $this->receiver;
    }

    public function receiverContext(): PhpMethodReceiver
    {
        return $this->receiverContext;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function startOffset(): int
    {
        return $this->startOffset;
    }

    public function endOffset(): int
    {
        return $this->endOffset;
    }

    public function methodStartOffset(): int
    {
        return $this->methodStartOffset;
    }

    public function methodEndOffset(): int
    {
        return $this->methodEndOffset;
    }

    /** @return list<PhpArgument> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function argument(int $position): ?PhpArgument
    {
        return $this->arguments[$position] ?? null;
    }

    public function className(): ?string
    {
        return $this->className;
    }

    public function enclosingMethod(): ?string
    {
        return $this->enclosingMethod;
    }

    public function scopeStartOffset(): ?int
    {
        return $this->scopeStartOffset;
    }
}
