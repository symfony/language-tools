<?php

namespace Symfony\Lsp\Check;

final class CheckProfiler
{
    public const PHASES = [
        'startup' => 'Executable startup',
        'configuration' => 'Configuration',
        'projectDiscovery' => 'Project discovery',
        'fileSelection' => 'File selection',
        'projectAnalysis' => 'Project analysis',
        'diagnostics' => 'Diagnostics',
        'resultProcessing' => 'Result processing',
    ];

    public const PROJECT_PHASES = [
        'sourceIndex' => 'Source indexing',
        'filePreparation' => 'File preparation',
        'runtimeIndex' => 'Runtime indexing',
        'diagnostics' => 'Diagnostics',
    ];

    private bool $enabled = false;
    private ?float $startedAt = null;
    /** @var array<string, float> */
    private array $phases = [];
    private ?float $baselineMatching = null;
    /** @var array<string, array{files: int, phases: array<string, float>, diagnosticProviders: array<string, float>, slowestFiles: array<string, float>}> */
    private array $projects = [];
    private ?CheckProfile $finished = null;

    public function start(bool $enabled, int|float|null $processStartedAt = null): void
    {
        $this->enabled = $enabled;
        $startedAt = (float) hrtime(true);
        $this->startedAt = $enabled ? (null === $processStartedAt ? $startedAt : (float) $processStartedAt) : null;
        $this->phases = $enabled && null !== $processStartedAt
            ? ['startup' => max(0.0, $startedAt - $processStartedAt)]
            : [];
        $this->baselineMatching = null;
        $this->projects = [];
        $this->finished = null;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function measurement(): ?float
    {
        return $this->enabled ? (float) hrtime(true) : null;
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function phase(string $phase, callable $work): mixed
    {
        return $this->measure($work, function (float $elapsed) use ($phase): void {
            $this->phases[$phase] = ($this->phases[$phase] ?? 0.0) + $elapsed;
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function projectPhase(CheckProject $project, string $phase, callable $work): mixed
    {
        return $this->measure($work, function (float $elapsed) use ($project, $phase): void {
            $profile = &$this->project($project);
            $profile['phases'][$phase] = ($profile['phases'][$phase] ?? 0.0) + $elapsed;
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function baselineMatching(callable $work): mixed
    {
        return $this->measure($work, function (float $elapsed): void {
            $this->baselineMatching = ($this->baselineMatching ?? 0.0) + $elapsed;
        });
    }

    /**
     * @template T
     *
     * @param callable(): T         $work
     * @param callable(float): void $record
     *
     * @return T
     */
    private function measure(callable $work, callable $record): mixed
    {
        $startedAt = $this->measurement();

        try {
            return $work();
        } finally {
            if (null !== $startedAt) {
                $record($this->elapsedNanoseconds($startedAt));
            }
        }
    }

    public function recordProjectFiles(CheckProject $project, int $files): void
    {
        if (!$this->enabled) {
            return;
        }

        $profile = &$this->project($project);
        $profile['files'] = $files;
    }

    /** @param array<string, float> $providerNanoseconds */
    public function recordDiagnosticProviders(CheckProject $project, array $providerNanoseconds): void
    {
        if (!$this->enabled) {
            return;
        }

        $profile = &$this->project($project);
        foreach ($providerNanoseconds as $provider => $nanoseconds) {
            $profile['diagnosticProviders'][$provider] = ($profile['diagnosticProviders'][$provider] ?? 0.0) + $nanoseconds;
        }
    }

    public function recordDiagnosticFile(CheckProject $project, string $path, ?float $startedAt): void
    {
        if (null === $startedAt) {
            return;
        }

        $elapsed = $this->elapsedNanoseconds($startedAt);
        $profile = &$this->project($project);
        $profile['phases']['diagnostics'] = ($profile['phases']['diagnostics'] ?? 0.0) + $elapsed;
        $profile['slowestFiles'][$path] = $elapsed;
        if (\count($profile['slowestFiles']) > 10) {
            $profile['slowestFiles'] = \array_slice($this->sorted($profile['slowestFiles']), 0, 10, true);
        }
    }

    public function finish(): ?CheckProfile
    {
        if (!$this->enabled || null === $this->startedAt) {
            return null;
        }
        if (null !== $this->finished) {
            return $this->finished;
        }

        $projects = [];
        ksort($this->projects);
        foreach ($this->projects as $id => $profile) {
            $projects[] = new CheckProfileProject(
                $id,
                $profile['files'],
                $this->millisecondsByName($profile['phases'], array_keys(self::PROJECT_PHASES)),
                $this->milliseconds($this->sorted($profile['diagnosticProviders'])),
                $this->milliseconds($this->sorted($profile['slowestFiles'])),
            );
        }

        return $this->finished = new CheckProfile(
            $this->millisecondsValue($this->elapsedNanoseconds($this->startedAt)),
            $this->millisecondsByName($this->phases, array_keys(self::PHASES)),
            null === $this->baselineMatching ? null : $this->millisecondsValue($this->baselineMatching),
            $projects,
        );
    }

    /** @return array{files: int, phases: array<string, float>, diagnosticProviders: array<string, float>, slowestFiles: array<string, float>} */
    private function &project(CheckProject $project): array
    {
        $id = $project->id;
        $this->projects[$id] ??= [
            'files' => 0,
            'phases' => [],
            'diagnosticProviders' => [],
            'slowestFiles' => [],
        ];

        return $this->projects[$id];
    }

    private function elapsedNanoseconds(float $startedAt): float
    {
        return max(0.0, (float) hrtime(true) - $startedAt);
    }

    /**
     * @param array<string, float> $values
     * @param list<string>         $names
     *
     * @return array<string, float|null>
     */
    private function millisecondsByName(array $values, array $names): array
    {
        $milliseconds = [];
        foreach ($names as $name) {
            $milliseconds[$name] = isset($values[$name]) ? $this->millisecondsValue($values[$name]) : null;
        }

        return $milliseconds;
    }

    /**
     * @param array<string, float> $values
     *
     * @return array<string, float>
     */
    private function milliseconds(array $values): array
    {
        return array_map($this->millisecondsValue(...), $values);
    }

    private function millisecondsValue(float $nanoseconds): float
    {
        return round($nanoseconds / 1_000_000, 1);
    }

    /**
     * @param array<string, float> $values
     *
     * @return array<string, float>
     */
    private function sorted(array $values): array
    {
        uksort($values, static fn (string $left, string $right): int => [$values[$right], $left] <=> [$values[$left], $right]);

        return $values;
    }
}
