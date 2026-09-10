<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Amp\Sync\LocalSemaphore;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Runtime\RuntimeBridgeTimingNormalizer;

use function Amp\async;
use function Amp\Future\await;

/** @phpstan-import-type RuntimeBridgeTimings from RuntimeBridgeTimingNormalizer */
final class MatrixCommand
{
    private const WORKING_TREE_LIMIT = 50;
    private const RUN_TIMING_KEYS = [
        'startupMilliseconds',
        'initializeMilliseconds',
        'sourceIndexMilliseconds',
        'runtimeIndexMilliseconds',
        'indexWaitMilliseconds',
        'manifestMilliseconds',
        'scenariosMilliseconds',
        'shutdownMilliseconds',
        'totalMilliseconds',
    ];

    /**
     * @param \Closure(string): void $output
     */
    public function __construct(
        private ProvisionerInterface $provisioner,
        private SetupRegistry $setups,
        private HarnessInterface $harness,
        private RunClassifier $classifier,
        private ProcessRunnerInterface $processes,
        private Filesystem $filesystem,
        private RuntimeBridgeTimingNormalizer $runtimeBridgeTimingNormalizer,
        private \Closure $output,
        private readonly DiagnosticCheckHarness $diagnostics,
        private readonly ScenarioManifestLoader $manifests = new ScenarioManifestLoader(),
        private readonly ScenarioEvidence $evidence = new ScenarioEvidence(),
    ) {
    }

    /**
     * @param list<ProjectConfiguration> $configurations
     */
    public function run(array $configurations, string $outputDirectory, int $jobs = 4, bool $enforceBudgets = true): int
    {
        if ($jobs < 1) {
            throw new \InvalidArgumentException('The dogfood job count must be positive.');
        }

        $startedAt = hrtime(true);
        $this->filesystem->mkdir($outputDirectory);
        $semaphore = new LocalSemaphore($jobs);
        /** @var array<int, \Amp\Future<ProjectReport>> $futures */
        $futures = [];
        foreach ($configurations as $index => $configuration) {
            $futures[$index] = async(function () use ($configuration, $outputDirectory, $semaphore, $enforceBudgets): ProjectReport {
                $lock = $semaphore->acquire();
                try {
                    $report = $this->runProject($configuration, Path::join($outputDirectory, $configuration->name), $enforceBudgets);
                    ($this->output)($this->formatLine($report));

                    return $report;
                } finally {
                    $lock->release();
                }
            });
        }
        $projectReports = await($futures);
        ksort($projectReports);
        $failed = false;
        $reports = [];
        foreach ($projectReports as $report) {
            $reports[] = $report->toArray();
            $failed = $failed || !$report->ok();
        }
        $tools = $this->toolVersions();
        $totalMilliseconds = $this->elapsedMilliseconds($startedAt);
        $this->writeJson(Path::join($outputDirectory, 'summary.json'), [
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'tools' => $tools,
            'jobs' => $jobs,
            'projects' => $reports,
            'timings' => ['totalMilliseconds' => $totalMilliseconds],
            'ok' => !$failed,
        ]);
        ($this->output)(\sprintf('Total: %.1fs', $totalMilliseconds / 1000));
        ($this->output)(\sprintf('Artifacts: %s', $outputDirectory));

        return $failed ? 1 : 0;
    }

    private function runProject(ProjectConfiguration $configuration, string $artifactDirectory, bool $enforceBudgets): ProjectReport
    {
        $startedAt = hrtime(true);
        $this->filesystem->mkdir($artifactDirectory);
        $report = new ProjectReport($configuration);
        try {
            $manifest = $this->manifests->load($configuration->scenarioFile, $configuration->revision);
            $report->expectationFingerprint = hash('sha256', json_encode([$manifest->scenarios, $manifest->diagnostics], \JSON_THROW_ON_ERROR));
            if (!$this->evidence->hasPositiveCheck($manifest)) {
                throw new ConfigurationException('A matrix manifest must include a positive behavioral expectation.');
            }
            $report->knownGaps = array_values(array_filter($manifest->diagnostics, static fn (array $diagnostic): bool => 'known-gap' === $diagnostic['kind']));
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            $report->failure = new ProjectFailure('scenario', $error->getMessage());
            $report->timings['totalMilliseconds'] = $this->elapsedMilliseconds($startedAt);
            $this->writeJson(Path::join($artifactDirectory, 'project.json'), $report->toArray());

            return $report;
        }

        $provisionStartedAt = hrtime(true);
        try {
            $checkout = $this->provisioner->provision($configuration);
        } catch (ProvisioningException $e) {
            $report->timings['provisionMilliseconds'] = $this->elapsedMilliseconds($provisionStartedAt);
            $report->timings['totalMilliseconds'] = $this->elapsedMilliseconds($startedAt);
            $report->failure = new ProjectFailure('provisioning', $e->getMessage());
            $this->writeJson(Path::join($artifactDirectory, 'project.json'), $report->toArray());

            return $report;
        }
        $report->timings['provisionMilliseconds'] = $this->elapsedMilliseconds($provisionStartedAt);

        try {
            $applicationRoot = null === $configuration->directory ? $checkout : Path::join($checkout, $configuration->directory);
            $setupStartedAt = hrtime(true);
            try {
                if (!is_file(Path::join($applicationRoot, 'composer.json'))) {
                    throw new SetupException(\sprintf('No composer.json in "%s".', $applicationRoot));
                }
                $this->setups->get($configuration->setup)->setUp($configuration, $applicationRoot);
                $report->workingTree = $this->workingTree($checkout);
                $unexpected = array_values(array_diff($report->workingTree['modified'], $configuration->setupChanges));
                if ([] !== $unexpected) {
                    throw new SetupException(\sprintf('Setup modified tracked upstream files: %s.', implode(', ', $unexpected)));
                }
            } catch (SetupException $e) {
                $report->failure = new ProjectFailure('setup', $e->getMessage());
            }
            $report->timings['setupMilliseconds'] = $this->elapsedMilliseconds($setupStartedAt);

            if (null === $report->failure) {
                $report->composerLockSha256 = hash_file('sha256', Path::join($applicationRoot, 'composer.lock')) ?: null;
                $report->frameworkBundle = $this->frameworkBundleVersion($applicationRoot);

                // dev caches are not invalidated by extractor changes, so a stale
                // cache would report the previous build's behavior
                $this->filesystem->remove(Path::join($applicationRoot, 'var/symfony-lsp/dev'));
                $cold = $this->harness->run($configuration, $applicationRoot);
                $this->filesystem->dumpFile(Path::join($artifactDirectory, 'cold.json'), '' !== $cold->rawOutput ? $cold->rawOutput : $cold->errorOutput);
                $report->cold = $this->summarize($cold, $configuration);

                $warm = $this->harness->run($configuration, $applicationRoot);
                $this->filesystem->dumpFile(Path::join($artifactDirectory, 'warm.json'), '' !== $warm->rawOutput ? $warm->rawOutput : $warm->errorOutput);
                $report->warm = $this->summarize($warm, $configuration);
                if ([] === $report->cold->layers && [] === $report->warm->layers) {
                    if (!$this->evidence->covers($manifest, $cold->result ?? []) || !$this->evidence->covers($manifest, $warm->result ?? [])) {
                        $report->failure = new ProjectFailure('scenario', 'The harness did not verify every declared scenario expectation.');
                    } elseif ($this->evidence->semantics($cold->result ?? []) !== $this->evidence->semantics($warm->result ?? [])) {
                        $report->failure = new ProjectFailure('cache-parity', 'Cold and warm scenario responses differ.');
                    }
                }
                if ('ready' === $report->warm->source && $this->expectedRuntimeState($configuration) === $report->warm->runtime) {
                    $report->diagnostics = $this->diagnostics->run($configuration, $applicationRoot);
                    $this->writeJson(Path::join($artifactDirectory, 'diagnostics.json'), $report->diagnostics->toArray());
                    if (!$report->diagnostics->ok()) {
                        $report->failure ??= new ProjectFailure('diagnostics', 'Whole-project analysis failed: '.$report->diagnostics->failure.'.');
                    } elseif (null === $report->diagnostics->analyzedFiles || 0 === $report->diagnostics->analyzedFiles) {
                        $report->failure ??= new ProjectFailure('diagnostics', 'Whole-project analysis did not report any analyzed files.');
                    } elseif ($this->evidence->diagnostics($manifest->diagnostics) !== $this->evidence->diagnostics($report->diagnostics->diagnostics)) {
                        $report->failure ??= new ProjectFailure('diagnostics', $this->evidence->diagnosticDifference($manifest->diagnostics, $report->diagnostics->diagnostics));
                    }
                }
                if ($enforceBudgets) {
                    $report->failure ??= $this->budgetFailure($report);
                }
            }
        } finally {
            $releaseStartedAt = hrtime(true);
            $this->provisioner->release($configuration);
            $report->timings['releaseMilliseconds'] = $this->elapsedMilliseconds($releaseStartedAt);
        }
        $report->timings['totalMilliseconds'] = $this->elapsedMilliseconds($startedAt);
        $this->writeJson(Path::join($artifactDirectory, 'project.json'), $report->toArray());

        return $report;
    }

    private function budgetFailure(ProjectReport $report): ?ProjectFailure
    {
        $configuration = $report->configuration;
        $cold = $report->cold;
        $coldCpu = null === $cold || [] !== $cold->layers ? null : $cold->timings['cpuMilliseconds'] ?? null;
        if (null !== $coldCpu && $coldCpu > $configuration->coldRunCpuBudget * 1000) {
            return new ProjectFailure('budget', \sprintf(
                'The cold run used %.1fs of CPU time, over the %ds budget (%s).',
                $coldCpu / 1000,
                $configuration->coldRunCpuBudget,
                $this->describeTimings([
                    'wall time' => $cold->timings['processMilliseconds'] ?? null,
                    'index wait' => $cold->timings['indexWaitMilliseconds'] ?? null,
                    'source index' => $cold->timings['sourceIndexMilliseconds'] ?? null,
                    'runtime index' => $cold->timings['runtimeIndexMilliseconds'] ?? null,
                ]),
            ));
        }
        $diagnostics = $report->diagnostics;
        if (null !== $diagnostics && $diagnostics->ok() && null !== $diagnostics->cpuMilliseconds && $diagnostics->cpuMilliseconds > $configuration->checkCpuBudget * 1000) {
            return new ProjectFailure('budget', \sprintf(
                'Whole-project analysis used %.1fs of CPU time, over the %ds budget (%s).',
                $diagnostics->cpuMilliseconds / 1000,
                $configuration->checkCpuBudget,
                $this->describeTimings([
                    'wall time' => $diagnostics->milliseconds,
                    'startup' => $diagnostics->phasesMilliseconds['startup'] ?? null,
                    'discovery' => $diagnostics->phasesMilliseconds['projectDiscovery'] ?? null,
                    'selection' => $diagnostics->phasesMilliseconds['fileSelection'] ?? null,
                    'source index' => $diagnostics->projectPhasesMilliseconds['sourceIndex'] ?? null,
                    'runtime index' => $diagnostics->projectPhasesMilliseconds['runtimeIndex'] ?? null,
                    'diagnostics' => $diagnostics->phasesMilliseconds['diagnostics'] ?? null,
                ]),
            ));
        }

        return null;
    }

    /** @param array<string, float|null> $timings */
    private function describeTimings(array $timings): string
    {
        $parts = [];
        foreach ($timings as $label => $milliseconds) {
            $parts[] = \sprintf('%s %s', $label, null === $milliseconds ? 'n/a' : \sprintf('%.1fs', $milliseconds / 1000));
        }

        return implode(', ', $parts);
    }

    private function cpuSeconds(?float $milliseconds): string
    {
        return null === $milliseconds ? 'n/a' : \sprintf('%.1f', $milliseconds / 1000);
    }

    private function summarize(HarnessResult $run, ProjectConfiguration $configuration): RunSummary
    {
        $result = $run->result ?? [];
        $scenarioCount = $result['scenarioCount'] ?? null;
        $requestCount = $result['requestCount'] ?? null;
        $checks = 0;
        $failures = 0;
        $maxMilliseconds = 0.0;
        foreach (\is_array($result['scenarios'] ?? null) ? $result['scenarios'] : [] as $scenario) {
            if (!\is_array($scenario) || !\is_array($scenario['checks'] ?? null)) {
                ++$failures;
                continue;
            }
            if ('pass' !== ($scenario['status'] ?? null)) {
                ++$failures;
            }
            foreach ($scenario['checks'] as $check) {
                if (!\is_array($check)) {
                    continue;
                }
                ++$checks;
                $milliseconds = $check['milliseconds'] ?? null;
                if (\is_int($milliseconds) || \is_float($milliseconds)) {
                    $maxMilliseconds = max($maxMilliseconds, (float) $milliseconds);
                }
            }
        }
        $serverVersion = $result['serverVersion'] ?? null;
        $violations = $result['violations'] ?? null;
        /** @var RuntimeBridgeTimings|null $runtimeBridgeTimings */
        $runtimeBridgeTimings = $this->runtimeBridgeTimingNormalizer->normalize($result['runtimeBridgeTimings'] ?? null);

        return new RunSummary(
            $this->classifier->classify($run, $configuration->analysisMode),
            $this->classifier->indexState($result, 'source'),
            $this->classifier->indexState($result, 'runtime'),
            \is_int($scenarioCount) ? $scenarioCount : 0,
            $checks,
            \is_int($requestCount) ? $requestCount : 0,
            $failures,
            \is_array($violations) ? \count($violations) : 0,
            $maxMilliseconds,
            \is_string($serverVersion) ? $serverVersion : null,
            $this->runTimings($run, $result),
            $runtimeBridgeTimings,
        );
    }

    /**
     * @param array<mixed> $result
     *
     * @return array<string, float|null>
     */
    private function runTimings(HarnessResult $run, array $result): array
    {
        $timings = [
            'manifestMilliseconds' => $run->manifestMilliseconds,
            'processMilliseconds' => $run->processMilliseconds,
            'cpuMilliseconds' => $run->cpuMilliseconds,
        ];
        $reported = $result['timings'] ?? null;
        if (!\is_array($reported)) {
            return $timings;
        }
        foreach (self::RUN_TIMING_KEYS as $key) {
            $value = $reported[$key] ?? null;
            if (\is_int($value) || \is_float($value)) {
                $timings[$key] = (float) $value;
            } elseif (\array_key_exists($key, $reported) && null === $value) {
                $timings[$key] = null;
            }
        }

        return $timings;
    }

    /**
     * @return array{modified: list<string>, untracked: int}
     */
    private function workingTree(string $checkout): array
    {
        $result = $this->processes->run(['git', '-C', $checkout, 'status', '--porcelain']);
        if (!$result->successful()) {
            throw new SetupException('Unable to inspect the post-setup working tree.');
        }
        $modified = [];
        $untracked = 0;
        foreach (preg_split('/\R/', $result->standardOutput, flags: \PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            if (str_starts_with($line, '??')) {
                ++$untracked;
            } elseif (\count($modified) < self::WORKING_TREE_LIMIT) {
                $modified[] = substr($line, 3);
            }
        }

        return ['modified' => $modified, 'untracked' => $untracked];
    }

    private function frameworkBundleVersion(string $applicationRoot): ?string
    {
        $contents = @file_get_contents(Path::join($applicationRoot, 'composer.lock'));
        if (false === $contents) {
            return null;
        }
        try {
            $lock = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($lock)) {
            return null;
        }
        foreach (['packages', 'packages-dev'] as $section) {
            foreach (\is_array($lock[$section] ?? null) ? $lock[$section] : [] as $package) {
                if (!\is_array($package) || 'symfony/framework-bundle' !== ($package['name'] ?? null)) {
                    continue;
                }
                $version = $package['version'] ?? null;
                if (\is_string($version)) {
                    return ltrim($version, 'v');
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function toolVersions(): array
    {
        $versions = ['php' => \PHP_VERSION];
        foreach (['git' => ['git', '--version'], 'composer' => ['composer', '--version', '--no-ansi']] as $tool => $command) {
            $result = $this->processes->run($command);
            $versions[$tool] = $result->successful() ? trim($result->standardOutput) : 'unknown';
        }

        return $versions;
    }

    private function formatLine(ProjectReport $report): string
    {
        if (null !== $report->failure) {
            return \sprintf('%-28s mode=%-11s %s: %s time=%.1fs', $report->configuration->name, $report->configuration->analysisMode, $report->failure->layer, $report->failure->message, ($report->timings['totalMilliseconds'] ?? 0.0) / 1000);
        }
        $cold = $report->cold ?? throw new \LogicException('Missing cold run.');
        $warm = $report->warm ?? throw new \LogicException('Missing warm run.');

        return \sprintf(
            '%-28s mode=%-11s cold=%-12s warm=%-12s scenarios=%2d checks=%3d requests=%3d max=%6.1fms failures=%d gaps=%d files=%d cold-cpu=%s/%ds check-cpu=%s/%ds time=%.1fs',
            $report->configuration->name,
            $report->configuration->analysisMode,
            [] === $cold->layers ? 'ok' : implode(',', $cold->layers),
            [] === $warm->layers ? 'ok' : implode(',', $warm->layers),
            $warm->scenarios,
            $cold->checks + $warm->checks,
            $cold->requests + $warm->requests,
            max($cold->maxMilliseconds, $warm->maxMilliseconds),
            $cold->failures + $warm->failures,
            \count($report->knownGaps),
            $report->diagnostics->analyzedFiles ?? 0,
            $this->cpuSeconds($cold->timings['cpuMilliseconds'] ?? null),
            $report->configuration->coldRunCpuBudget,
            $this->cpuSeconds($report->diagnostics?->cpuMilliseconds),
            $report->configuration->checkCpuBudget,
            ($report->timings['totalMilliseconds'] ?? 0.0) / 1000,
        );
    }

    /** @return 'ready'|'disabled' */
    private function expectedRuntimeState(ProjectConfiguration $configuration): string
    {
        return 'source-only' === $configuration->analysisMode ? 'disabled' : 'ready';
    }

    private function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 1);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $this->filesystem->dumpFile($path, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
    }
}
