<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $standardOutput,
        public readonly string $errorOutput,
        public readonly bool $timedOut,
    ) {
    }

    public function successful(): bool
    {
        return 0 === $this->exitCode && !$this->timedOut;
    }
}
