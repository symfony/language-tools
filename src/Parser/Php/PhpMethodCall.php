<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpMethodCall
{
    /**
     * @param list<PhpArgument> $arguments
     */
    public function __construct(
        private readonly string $receiver,
        private readonly string $method,
        private readonly int $startOffset,
        private readonly array $arguments,
    ) {
    }

    public function receiver(): string
    {
        return $this->receiver;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function startOffset(): int
    {
        return $this->startOffset;
    }

    public function argument(int $position): ?PhpArgument
    {
        return $this->arguments[$position] ?? null;
    }
}
