<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class RunSummary
{
    /**
     * @param list<string> $layers
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
        ];
    }
}
