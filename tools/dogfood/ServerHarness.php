<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ServerHarness implements HarnessInterface
{
    private const REQUEST_BUDGET = 180.0;

    public function __construct(
        private ProcessRunnerInterface $processes,
        private string $harnessPath,
        private string $serverPath,
    ) {
    }

    public function run(ProjectConfiguration $configuration, string $applicationRoot): HarnessResult
    {
        $result = $this->processes->run([
            \PHP_BINARY,
            $this->harnessPath,
            '--environment='.$configuration->environment,
            '--index-timeout='.$configuration->indexTimeout,
            '--request-timeout='.$configuration->requestTimeout,
            '--probe-roots='.implode(',', $configuration->probeRoots),
            '--probes-per-category='.$configuration->probesPerCategory,
            $this->serverPath,
            $applicationRoot,
        ], null, $configuration->indexTimeout + self::REQUEST_BUDGET * $configuration->probesPerCategory);
        $decoded = null;
        if ('' !== $result->standardOutput) {
            try {
                $decoded = json_decode($result->standardOutput, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
            }
        }

        return new HarnessResult(
            $result->exitCode,
            $result->timedOut,
            \is_array($decoded) ? $decoded : null,
            $result->standardOutput,
            $result->errorOutput,
        );
    }
}
