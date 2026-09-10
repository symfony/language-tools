<?php

namespace Symfony\Lsp\Tools\Dogfood;

/** @phpstan-import-type ScenarioExpectations from ScenarioManifest */
final class ServerHarness implements HarnessInterface
{
    public const PROCESS_EXIT_TIMEOUT = 5.0;

    public function __construct(
        private readonly ProcessRunnerInterface $processes,
        private readonly string $harnessPath,
        private readonly string $serverPath,
        private readonly ScenarioManifestLoader $manifests = new ScenarioManifestLoader(),
    ) {
    }

    public function run(ProjectConfiguration $configuration, string $applicationRoot): HarnessResult
    {
        $startedAt = hrtime(true);
        $manifest = $this->manifests->load($configuration->scenarioFile, $configuration->revision);
        $manifestMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
        $requests = 2;
        foreach ($manifest->scenarios as $scenario) {
            $requests += $this->requestBudget($scenario['expect']);
            if (isset($scenario['edit'])) {
                $requests += $this->requestBudget($scenario['edit']['expect']) + $this->requestBudget($scenario['expect']);
                if (isset($scenario['edit']['afterFix'])) {
                    $requests += 4 + $this->requestBudget($scenario['edit']['afterFix']);
                }
            }
        }
        $timeout = 10.0 + $configuration->indexTimeout + $requests * $configuration->requestTimeout + self::PROCESS_EXIT_TIMEOUT + 3.0;
        $startedAt = hrtime(true);
        $result = $this->processes->run([
            \PHP_BINARY,
            $this->harnessPath,
            '--environment='.$configuration->environment,
            '--index-timeout='.$configuration->indexTimeout,
            '--request-timeout='.$configuration->requestTimeout,
            '--scenarios='.$configuration->scenarioFile,
            '--revision='.$configuration->revision,
            ...('source-only' === $configuration->analysisMode ? ['--source-only'] : []),
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
            round($manifestMilliseconds, 1),
            round((hrtime(true) - $startedAt) / 1_000_000, 1),
            $result->cpuMilliseconds,
        );
    }

    /** @param ScenarioExpectations $expectations */
    private function requestBudget(array $expectations): int
    {
        $requests = 0;
        foreach (array_keys($expectations) as $method) {
            $requests += match ($method) {
                'diagnostics' => 3,
                'codeAction' => 4,
                default => 1,
            };
        }

        return $requests;
    }
}
