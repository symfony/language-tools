<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Symfony\Component\Filesystem\Path;

/** @phpstan-import-type DogfoodDiagnostic from DiagnosticCheckResult */
final class DiagnosticCheckHarness
{
    private const CHECK_BUDGET_MARGIN = 60;
    private const PROCESS_TERMINATION_ALLOWANCE = 10.0;
    private const ACCEPTED_EXIT_CODES = [0, 10];
    private const SEVERITIES = ['error', 'warning', 'information', 'hint'];

    public function __construct(
        private ProcessRunnerInterface $processes,
        private string $serverPath,
        private readonly ResponseFingerprint $fingerprint = new ResponseFingerprint(),
    ) {
    }

    public function run(ProjectConfiguration $configuration, string $applicationRoot): DiagnosticCheckResult
    {
        if (!Path::isAbsolute($applicationRoot) || !is_dir($applicationRoot)) {
            return new DiagnosticCheckResult('application-root-invalid', null, [], 0.0, null);
        }

        $startedAt = hrtime(true);
        $process = $this->processes->run(
            $this->command($configuration, $applicationRoot),
            $applicationRoot,
            $this->checkTimeout($configuration) + self::PROCESS_TERMINATION_ALLOWANCE,
            $configuration->environmentVariables,
        );
        $milliseconds = round((hrtime(true) - $startedAt) / 1_000_000, 1);
        if ($process->timedOut) {
            return new DiagnosticCheckResult('process-timeout', $process->exitCode, [], $milliseconds, null);
        }

        $report = $this->decode($process->standardOutput);
        if (null === $report) {
            return new DiagnosticCheckResult('report-not-json', $process->exitCode, [], $milliseconds, null);
        }
        $analyzedFiles = $this->analyzedFiles($report);
        $failure = $this->verify($report, $process->exitCode);
        if (null !== $failure) {
            return new DiagnosticCheckResult($failure, $process->exitCode, [], $milliseconds, $analyzedFiles);
        }
        $diagnostics = $this->diagnostics($report, $applicationRoot);
        if (\is_string($diagnostics)) {
            return new DiagnosticCheckResult($diagnostics, $process->exitCode, [], $milliseconds, $analyzedFiles);
        }

        return new DiagnosticCheckResult(null, $process->exitCode, $diagnostics, $milliseconds, $analyzedFiles);
    }

    /** @return list<string> */
    private function command(ProjectConfiguration $configuration, string $applicationRoot): array
    {
        return [
            $this->serverPath,
            'check',
            '--format=json',
            '--profile',
            '--runtime-indexing',
            '--workspace='.$applicationRoot,
            '--environment='.$configuration->environment,
            '--bridge-timeout='.$configuration->indexTimeout,
            '--timeout='.$this->checkTimeout($configuration),
        ];
    }

    private function checkTimeout(ProjectConfiguration $configuration): int
    {
        return 2 * $configuration->indexTimeout + self::CHECK_BUDGET_MARGIN;
    }

    /** @return array<mixed>|null */
    private function decode(string $output): ?array
    {
        try {
            $decoded = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    /** @param array<mixed> $report */
    private function verify(array $report, int $exitCode): ?string
    {
        $projects = $report['projects'] ?? null;
        $errors = $report['errors'] ?? null;
        $baseline = $report['baseline'] ?? null;
        if (1 !== ($report['schemaVersion'] ?? null)
            || !\is_array($projects)
            || !\is_array($report['diagnostics'] ?? null)
            || !\is_array($errors)
            || !\is_array($baseline)
        ) {
            return 'report-schema';
        }
        if ([] !== $errors) {
            return 'check-errors';
        }
        if (true !== ($report['complete'] ?? null)) {
            return 'analysis-incomplete';
        }
        if (!\in_array($exitCode, self::ACCEPTED_EXIT_CODES, true)) {
            return 'exit-code';
        }
        if ([] === $projects) {
            return 'no-projects';
        }
        foreach ($projects as $project) {
            if (!\is_array($project)
                || true !== ($project['complete'] ?? null)
                || 'runtime' !== $this->section($project, 'analysis', 'mode')
                || 'ready' !== $this->section($project, 'source', 'state')
                || 'ready' !== $this->section($project, 'runtime', 'state')
            ) {
                return 'project-not-runtime-ready';
            }
        }
        if (null !== ($baseline['path'] ?? null) || 'none' !== ($baseline['mode'] ?? null) || [] !== ($baseline['stale'] ?? null)) {
            return 'baseline-active';
        }

        return null;
    }

    /**
     * @param array<mixed> $report
     *
     * @return list<DogfoodDiagnostic>|string failure reason when a diagnostic cannot be projected
     */
    private function diagnostics(array $report, string $applicationRoot): array|string
    {
        $reported = $report['diagnostics'] ?? null;
        if (!\is_array($reported)) {
            return 'report-schema';
        }

        $diagnostics = [];
        foreach ($reported as $reportedDiagnostic) {
            if (!\is_array($reportedDiagnostic)) {
                return 'diagnostic-invalid';
            }
            if ('active' !== ($reportedDiagnostic['baseline'] ?? null)) {
                return 'baseline-active';
            }
            $file = $reportedDiagnostic['workspacePath'] ?? null;
            $code = $reportedDiagnostic['code'] ?? null;
            $severity = $reportedDiagnostic['severity'] ?? null;
            $message = $reportedDiagnostic['message'] ?? null;
            $range = $this->range($reportedDiagnostic['range'] ?? null);
            if (!\is_string($file)
                || !\is_string($code)
                || '' === $code
                || !\is_string($severity)
                || !\in_array($severity, self::SEVERITIES, true)
                || null === $range || !\is_string($message) || '' === $message
            ) {
                return 'diagnostic-invalid';
            }
            if (!$this->applicationRelative($file)) {
                return 'diagnostic-path-invalid';
            }
            $diagnostics[] = [
                'path' => $file,
                'code' => $code,
                'severity' => $severity,
                'range' => $range,
                'messageHash' => $this->fingerprint->hash($message, $applicationRoot),
            ];
        }
        usort($diagnostics, static fn (array $left, array $right): int => self::sortKey($left) <=> self::sortKey($right));

        return $diagnostics;
    }

    /**
     * @param DogfoodDiagnostic $diagnostic
     *
     * @return list<int|string>
     */
    private static function sortKey(array $diagnostic): array
    {
        return [
            $diagnostic['path'],
            $diagnostic['range']['start']['line'],
            $diagnostic['range']['start']['character'],
            $diagnostic['range']['end']['line'],
            $diagnostic['range']['end']['character'],
            $diagnostic['severity'],
            $diagnostic['code'],
        ];
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}}|null */
    private function range(mixed $range): ?array
    {
        $start = \is_array($range) ? ($range['start'] ?? null) : null;
        $end = \is_array($range) ? ($range['end'] ?? null) : null;
        $startLine = \is_array($start) ? ($start['line'] ?? null) : null;
        $startCharacter = \is_array($start) ? ($start['character'] ?? null) : null;
        $endLine = \is_array($end) ? ($end['line'] ?? null) : null;
        $endCharacter = \is_array($end) ? ($end['character'] ?? null) : null;
        if (!\is_int($startLine) || !\is_int($startCharacter) || !\is_int($endLine) || !\is_int($endCharacter)
            || $startLine < 0 || $startCharacter < 0 || $endCharacter < 0
            || $endLine < $startLine
            || ($endLine === $startLine && $endCharacter < $startCharacter)
        ) {
            return null;
        }

        return [
            'start' => ['line' => $startLine, 'character' => $startCharacter],
            'end' => ['line' => $endLine, 'character' => $endCharacter],
        ];
    }

    private function applicationRelative(string $path): bool
    {
        if ('' === $path || Path::isAbsolute($path) || str_contains($path, '\\')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return false;
            }
        }

        return true;
    }

    /** @param array<mixed> $report */
    private function analyzedFiles(array $report): ?int
    {
        $profile = $report['profile'] ?? null;
        $projects = \is_array($profile) ? ($profile['projects'] ?? null) : null;
        if (!\is_array($projects)) {
            return null;
        }
        $files = 0;
        foreach ($projects as $project) {
            $count = \is_array($project) ? ($project['files'] ?? null) : null;
            if (!\is_int($count) || $count < 0) {
                return null;
            }
            $files += $count;
        }

        return $files;
    }

    /** @param array<mixed> $values */
    private function section(array $values, string $section, string $key): ?string
    {
        $part = $values[$section] ?? null;
        $value = \is_array($part) ? ($part[$key] ?? null) : null;

        return \is_string($value) ? $value : null;
    }
}
