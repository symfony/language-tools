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
        'probeDiscoveryMilliseconds',
        'requestsMilliseconds',
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
        private SupportScorer $scorer = new SupportScorer(),
    ) {
    }

    /**
     * @param list<ProjectConfiguration> $configurations
     */
    public function run(array $configurations, string $outputDirectory, int $jobs = 4): int
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
            $futures[$index] = async(function () use ($configuration, $outputDirectory, $semaphore): ProjectReport {
                $lock = $semaphore->acquire();
                try {
                    $report = $this->runProject($configuration, Path::join($outputDirectory, $configuration->name));
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

    private function runProject(ProjectConfiguration $configuration, string $artifactDirectory): ProjectReport
    {
        $startedAt = hrtime(true);
        $this->filesystem->mkdir($artifactDirectory);
        $report = new ProjectReport($configuration);

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
                $report->cold = $this->summarize($cold, $configuration->name);

                $warm = $this->harness->run($configuration, $applicationRoot);
                $this->filesystem->dumpFile(Path::join($artifactDirectory, 'warm.json'), '' !== $warm->rawOutput ? $warm->rawOutput : $warm->errorOutput);
                $report->warm = $this->summarize($warm, $configuration->name);
                if ([] === $report->cold->layers
                    && [] === $report->warm->layers
                    && $this->diagnostics($cold) !== $this->diagnostics($warm)
                ) {
                    $report->failure = new ProjectFailure('cache-parity', 'Cold and warm diagnostic publications differ.');
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

    /** @return list<array<array-key, mixed>> */
    private function diagnostics(HarnessResult $run): array
    {
        $diagnostics = $run->result['diagnostics'] ?? null;
        if (!\is_array($diagnostics)) {
            return [];
        }

        $normalized = [];
        foreach ($diagnostics as $publication) {
            if (!\is_array($publication)) {
                $normalized[] = $this->diagnosticEntry('malformed-publication', $publication);

                continue;
            }
            $items = $publication['items'] ?? null;
            if (\is_array($items)) {
                $items = array_map(
                    fn (mixed $item): array => \is_array($item)
                        ? $this->diagnosticEntry('item', $this->normalizeDiagnosticValue($item))
                        : $this->diagnosticEntry('malformed-item', $item),
                    $items,
                );
                usort($items, static fn (array $left, array $right): int => serialize($left) <=> serialize($right));
                $publication['items'] = $items;
            }
            $normalized[] = $this->diagnosticEntry('publication', $this->normalizeDiagnosticValue($publication));
        }
        usort($normalized, static fn (array $left, array $right): int => serialize($left) <=> serialize($right));

        return $normalized;
    }

    /** @return array{type: string, value: mixed} */
    private function diagnosticEntry(string $type, mixed $value): array
    {
        return ['type' => $type, 'value' => $value];
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function normalizeDiagnosticValue(array $value): array
    {
        foreach ($value as $key => $child) {
            if (\is_array($child)) {
                $value[$key] = $this->normalizeDiagnosticValue($child);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function summarize(HarnessResult $run, string $project): RunSummary
    {
        $result = $run->result ?? [];
        $probeCount = $result['probeCount'] ?? null;
        $requestErrors = 0;
        $maxMilliseconds = 0.0;
        foreach (\is_array($result['probes'] ?? null) ? $result['probes'] : [] as $probe) {
            if (!\is_array($probe) || !\is_array($probe['requests'] ?? null)) {
                continue;
            }
            foreach ($probe['requests'] as $request) {
                if (!\is_array($request)) {
                    continue;
                }
                if (null !== ($request['error'] ?? null)) {
                    ++$requestErrors;
                }
                $milliseconds = $request['milliseconds'] ?? null;
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
            $this->classifier->classify($run),
            $this->classifier->indexState($result, 'source'),
            $this->classifier->indexState($result, 'runtime'),
            \is_int($probeCount) ? $probeCount : 0,
            $requestErrors,
            \is_array($violations) ? \count($violations) : 0,
            $maxMilliseconds,
            \is_string($serverVersion) ? $serverVersion : null,
            $this->scorer->score($result, $project)['score'] ?? null,
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
            'budgetProbeDiscoveryMilliseconds' => $run->probeDiscoveryMilliseconds,
            'processMilliseconds' => $run->processMilliseconds,
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
            return \sprintf('%-28s %s: %s time=%.1fs', $report->configuration->name, $report->failure->layer, $report->failure->message, ($report->timings['totalMilliseconds'] ?? 0.0) / 1000);
        }
        $cold = $report->cold ?? throw new \LogicException('Missing cold run.');
        $warm = $report->warm ?? throw new \LogicException('Missing warm run.');

        return \sprintf(
            '%-28s cold=%-12s warm=%-12s probes=%2d max=%6.1fms errors=%d%s time=%.1fs',
            $report->configuration->name,
            [] === $cold->layers ? 'ok' : implode(',', $cold->layers),
            [] === $warm->layers ? 'ok' : implode(',', $warm->layers),
            $warm->probes,
            max($cold->maxMilliseconds, $warm->maxMilliseconds),
            $cold->requestErrors + $warm->requestErrors,
            null === $warm->supportScore ? '' : \sprintf(' support=%5.1f%%', 100 * $warm->supportScore),
            ($report->timings['totalMilliseconds'] ?? 0.0) / 1000,
        );
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
