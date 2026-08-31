<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final readonly class BridgeProcessResult
{
    /**
     * @param array<array-key, mixed>|null $snapshot
     */
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public ?array $snapshot,
    ) {
    }
}
