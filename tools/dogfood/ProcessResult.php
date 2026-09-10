<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ProcessResult
{
    /**
     * @param float      $milliseconds    wall time of the process
     * @param float|null $cpuMilliseconds user and system CPU time of the process tree, null when it cannot be measured
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly string $standardOutput,
        public readonly string $errorOutput,
        public readonly bool $timedOut,
        public readonly float $milliseconds = 0.0,
        public readonly ?float $cpuMilliseconds = null,
    ) {
    }

    public function successful(): bool
    {
        return 0 === $this->exitCode && !$this->timedOut;
    }
}
