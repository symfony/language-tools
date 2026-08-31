<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ServerHarness implements HarnessInterface
{
    public const PROCESS_EXIT_TIMEOUT = 5.0;
    public const PROBE_METHODS = [
        'textDocument/completion',
        'textDocument/hover',
        'textDocument/definition',
        'textDocument/references',
        'textDocument/documentLink',
        'textDocument/codeLens',
        'textDocument/codeAction',
        'textDocument/prepareRename',
        'textDocument/rename',
    ];

    private const INITIALIZE_TIMEOUT = 10.0;
    private const NON_PROBE_REQUESTS = 2;
    private const PROCESS_OVERHEAD = self::PROCESS_EXIT_TIMEOUT + 3.0;

    public function __construct(
        private ProcessRunnerInterface $processes,
        private string $harnessPath,
        private string $serverPath,
    ) {
    }

    public function run(ProjectConfiguration $configuration, string $applicationRoot): HarnessResult
    {
        $probeDiscoveryStartedAt = hrtime(true);
        $probeCount = \count((new ProbeFinder($configuration->probeRoots, $configuration->probesPerCategory))->find($applicationRoot));
        $probeDiscoveryMilliseconds = (hrtime(true) - $probeDiscoveryStartedAt) / 1_000_000;
        $requestCount = self::NON_PROBE_REQUESTS + $probeCount * \count(self::PROBE_METHODS);
        $timeout = self::INITIALIZE_TIMEOUT + $configuration->indexTimeout + $requestCount * $configuration->requestTimeout + self::PROCESS_OVERHEAD;
        $processStartedAt = hrtime(true);
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
        ], null, $timeout, $configuration->environmentVariables);
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
            round($probeDiscoveryMilliseconds, 1),
            round((hrtime(true) - $processStartedAt) / 1_000_000, 1),
        );
    }
}
