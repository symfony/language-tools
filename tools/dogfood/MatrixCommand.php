<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class MatrixCommand
{
    private const WORKING_TREE_LIMIT = 50;

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
        private \Closure $output,
        private SupportScorer $scorer = new SupportScorer(),
    ) {
    }

    /**
     * @param list<ProjectConfiguration> $configurations
     */
    public function run(array $configurations, string $outputDirectory): int
    {
        $this->filesystem->mkdir($outputDirectory);
        $failed = false;
        $reports = [];
        foreach ($configurations as $configuration) {
            $report = $this->runProject($configuration, Path::join($outputDirectory, $configuration->name));
            $reports[] = $report->toArray();
            $failed = $failed || !$report->ok();
            ($this->output)($this->formatLine($report));
        }
        $this->writeJson(Path::join($outputDirectory, 'summary.json'), [
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'tools' => $this->toolVersions(),
            'projects' => $reports,
            'ok' => !$failed,
        ]);
        ($this->output)(\sprintf('Artifacts: %s', $outputDirectory));

        return $failed ? 1 : 0;
    }

    private function runProject(ProjectConfiguration $configuration, string $artifactDirectory): ProjectReport
    {
        $this->filesystem->mkdir($artifactDirectory);
        $report = new ProjectReport($configuration);

        try {
            $checkout = $this->provisioner->provision($configuration);
        } catch (ProvisioningException $e) {
            $report->failure = new ProjectFailure('provisioning', $e->getMessage());
            $this->writeJson(Path::join($artifactDirectory, 'project.json'), $report->toArray());

            return $report;
        }

        try {
            $applicationRoot = null === $configuration->directory ? $checkout : Path::join($checkout, $configuration->directory);
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
                $this->writeJson(Path::join($artifactDirectory, 'project.json'), $report->toArray());

                return $report;
            }
            $report->composerLockSha256 = hash_file('sha256', Path::join($applicationRoot, 'composer.lock')) ?: null;
            $report->frameworkBundle = $this->frameworkBundleVersion($applicationRoot);

            $cold = $this->harness->run($configuration, $applicationRoot);
            $this->filesystem->dumpFile(Path::join($artifactDirectory, 'cold.json'), '' !== $cold->rawOutput ? $cold->rawOutput : $cold->errorOutput);
            $report->cold = $this->summarize($cold, $configuration->name);

            $warm = $this->harness->run($configuration, $applicationRoot);
            $this->filesystem->dumpFile(Path::join($artifactDirectory, 'warm.json'), '' !== $warm->rawOutput ? $warm->rawOutput : $warm->errorOutput);
            $report->warm = $this->summarize($warm, $configuration->name);
        } finally {
            $this->provisioner->release($configuration);
        }
        $this->writeJson(Path::join($artifactDirectory, 'project.json'), $report->toArray());

        return $report;
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
        );
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
            return \sprintf('%-28s %s: %s', $report->configuration->name, $report->failure->layer, $report->failure->message);
        }
        $cold = $report->cold ?? throw new \LogicException('Missing cold run.');
        $warm = $report->warm ?? throw new \LogicException('Missing warm run.');

        return \sprintf(
            '%-28s cold=%-12s warm=%-12s probes=%2d max=%6.1fms errors=%d%s',
            $report->configuration->name,
            [] === $cold->layers ? 'ok' : implode(',', $cold->layers),
            [] === $warm->layers ? 'ok' : implode(',', $warm->layers),
            $warm->probes,
            max($cold->maxMilliseconds, $warm->maxMilliseconds),
            $cold->requestErrors + $warm->requestErrors,
            null === $warm->supportScore ? '' : \sprintf(' support=%5.1f%%', 100 * $warm->supportScore),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $this->filesystem->dumpFile($path, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
    }
}
