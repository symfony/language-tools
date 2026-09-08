<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Symfony\Lsp\Runtime\RuntimeBridgeTimingNormalizer;

/** @phpstan-import-type RuntimeBridgeTimings from RuntimeBridgeTimingNormalizer */
final class RunSummary
{
    /**
     * @param list<string>              $layers
     * @param array<string, float|null> $timings
     * @param RuntimeBridgeTimings|null $runtimeBridgeTimings
     */
    public function __construct(
        public readonly array $layers,
        public readonly string $source,
        public readonly string $runtime,
        public readonly int $scenarios,
        public readonly int $checks,
        public readonly int $requests,
        public readonly int $failures,
        public readonly int $violations,
        public readonly float $maxMilliseconds,
        public readonly ?string $serverVersion,
        public readonly array $timings = [],
        public readonly ?array $runtimeBridgeTimings = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'layers' => $this->layers,
            'source' => $this->source,
            'runtime' => $this->runtime,
            'scenarios' => $this->scenarios,
            'checks' => $this->checks,
            'requests' => $this->requests,
            'failures' => $this->failures,
            'violations' => $this->violations,
            'maxMilliseconds' => $this->maxMilliseconds,
            'serverVersion' => $this->serverVersion,
            'timings' => $this->timings,
            'runtimeBridgeTimings' => $this->runtimeBridgeTimings,
        ];
    }
}
