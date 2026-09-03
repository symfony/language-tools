<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectConfiguration;

final class CheckProfiler
{
    private const PHASES = [
        'startup',
        'configuration',
        'projectDiscovery',
        'fileSelection',
        'projectAnalysis',
        'diagnostics',
        'resultProcessing',
    ];

    private const PROJECT_PHASES = [
        'sourceIndex',
        'filePreparation',
        'runtimeIndex',
        'diagnostics',
    ];

    private bool $enabled = false;
    private ?float $startedAt = null;
    /** @var array<string, float> */
    private array $phases = [];
    private ?float $baselineMatching = null;
    /** @var array<string, array{files: int, phases: array<string, float>, diagnosticProviders: array<string, float>, slowestFiles: array<string, float>}> */
    private array $projects = [];
    private ?CheckProfile $finished = null;

    public function __construct(private readonly ProjectConfiguration $projectConfiguration)
    {
    }

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

    public function recordPhase(string $phase, ?float $startedAt): void
    {
        if (null === $startedAt) {
            return;
        }

        $this->phases[$phase] = ($this->phases[$phase] ?? 0.0) + $this->elapsedNanoseconds($startedAt);
    }

    public function recordBaselineMatching(?float $startedAt): void
    {
        if (null === $startedAt) {
            return;
        }

        $this->baselineMatching = ($this->baselineMatching ?? 0.0) + $this->elapsedNanoseconds($startedAt);
    }

    public function recordProjectFiles(Project $project, int $files): void
    {
        if (!$this->enabled) {
            return;
        }

        $profile = &$this->project($project);
        $profile['files'] = $files;
    }

    public function recordProjectPhase(Project $project, string $phase, ?float $startedAt): void
    {
        if (null === $startedAt) {
            return;
        }

        $profile = &$this->project($project);
        $profile['phases'][$phase] = ($profile['phases'][$phase] ?? 0.0) + $this->elapsedNanoseconds($startedAt);
    }

    /** @param array<string, float> $providerNanoseconds */
    public function recordDiagnosticProviders(Project $project, array $providerNanoseconds): void
    {
        if (!$this->enabled) {
            return;
        }

        $profile = &$this->project($project);
        foreach ($providerNanoseconds as $provider => $nanoseconds) {
            $profile['diagnosticProviders'][$provider] = ($profile['diagnosticProviders'][$provider] ?? 0.0) + $nanoseconds;
        }
    }

    public function recordDiagnosticFile(Project $project, string $path, ?float $startedAt): void
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
                $this->millisecondsByName($profile['phases'], self::PROJECT_PHASES),
                $this->milliseconds($this->sorted($profile['diagnosticProviders'])),
                $this->milliseconds($this->sorted($profile['slowestFiles'])),
            );
        }

        return $this->finished = new CheckProfile(
            $this->millisecondsValue($this->elapsedNanoseconds($this->startedAt)),
            $this->millisecondsByName($this->phases, self::PHASES),
            null === $this->baselineMatching ? null : $this->millisecondsValue($this->baselineMatching),
            $projects,
        );
    }

    /** @return array{files: int, phases: array<string, float>, diagnosticProviders: array<string, float>, slowestFiles: array<string, float>} */
    private function &project(Project $project): array
    {
        $id = $this->projectConfiguration->projectId($project);
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
