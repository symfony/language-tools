<?php

namespace Symfony\Lsp\Tools\Dogfood;

/** @phpstan-type DogfoodDiagnostic array{path: string, code: string, severity: string, messageHash: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}} */
final class DiagnosticCheckResult
{
    /**
     * @param list<DogfoodDiagnostic> $diagnostics
     * @param float                   $milliseconds              wall time of the check process
     * @param float|null              $cpuMilliseconds           CPU time of the check process tree, null when unmeasured
     * @param float|null              $profileMilliseconds       total reported by the check's own profile
     * @param array<string, float>    $phasesMilliseconds        check phases from the profile
     * @param array<string, float>    $projectPhasesMilliseconds project phases summed over the analyzed projects
     */
    public function __construct(
        public readonly ?string $failure,
        public readonly ?int $exitCode,
        public readonly array $diagnostics,
        public readonly float $milliseconds,
        public readonly ?int $analyzedFiles,
        public readonly string $analysisMode = 'runtime',
        public readonly ?float $cpuMilliseconds = null,
        public readonly ?float $profileMilliseconds = null,
        public readonly array $phasesMilliseconds = [],
        public readonly array $projectPhasesMilliseconds = [],
    ) {
    }

    public function ok(): bool
    {
        return null === $this->failure;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok(),
            'analysisMode' => $this->analysisMode,
            'failure' => $this->failure,
            'exitCode' => $this->exitCode,
            'analyzedFiles' => $this->analyzedFiles,
            'milliseconds' => $this->milliseconds,
            'cpuMilliseconds' => $this->cpuMilliseconds,
            'profileMilliseconds' => $this->profileMilliseconds,
            'phasesMilliseconds' => $this->phasesMilliseconds,
            'projectPhasesMilliseconds' => $this->projectPhasesMilliseconds,
            'diagnostics' => $this->diagnostics,
        ];
    }
}
