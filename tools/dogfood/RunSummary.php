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
        public readonly int $probes,
        public readonly int $requestErrors,
        public readonly int $violations,
        public readonly float $maxMilliseconds,
        public readonly ?string $serverVersion,
        public readonly ?float $supportScore = null,
        public readonly array $timings = [],
        public readonly ?array $runtimeBridgeTimings = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'layers' => $this->layers,
            'source' => $this->source,
            'runtime' => $this->runtime,
            'probes' => $this->probes,
            'requestErrors' => $this->requestErrors,
            'violations' => $this->violations,
            'maxMilliseconds' => $this->maxMilliseconds,
            'serverVersion' => $this->serverVersion,
            'supportScore' => $this->supportScore,
            'timings' => $this->timings,
            'runtimeBridgeTimings' => $this->runtimeBridgeTimings,
        ];
    }
}
