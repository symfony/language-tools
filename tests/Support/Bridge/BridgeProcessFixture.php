<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

use Symfony\Lsp\Runtime\NativeProcessRunner;

final class BridgeProcessFixture
{
    public function __construct(
        private readonly string $projectDirectory,
        private readonly float $timeout = 30.0,
    ) {
    }

    /**
     * @param list<string> $bridgeOptions
     * @param list<string> $phpOptions
     */
    public function run(array $bridgeOptions = [], array $phpOptions = []): BridgeProcessResult
    {
        $process = (new NativeProcessRunner($this->timeout))->run([
            \PHP_BINARY,
            ...$phpOptions,
            \dirname(__DIR__, 3).'/resources/bridge.php',
            '--project='.$this->projectDirectory,
            ...$bridgeOptions,
        ], $this->projectDirectory);

        try {
            $snapshot = json_decode($process->stdout, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($snapshot)) {
                $snapshot = null;
            }
        } catch (\JsonException) {
            $snapshot = null;
        }

        return new BridgeProcessResult($process->exitCode, $process->stdout, $process->stderr, $snapshot);
    }
}
