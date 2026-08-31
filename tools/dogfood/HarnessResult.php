<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class HarnessResult
{
    /**
     * @param array<mixed>|null $result decoded harness output, null when unavailable
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly bool $timedOut,
        public readonly ?array $result,
        public readonly string $rawOutput,
        public readonly string $errorOutput,
        public readonly float $probeDiscoveryMilliseconds = 0.0,
        public readonly float $processMilliseconds = 0.0,
    ) {
    }
}
