<?php

namespace Symfony\Lsp\Tools\Dogfood;

/** @phpstan-type DogfoodDiagnostic array{path: string, code: string, severity: string, messageHash: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}} */
final class DiagnosticCheckResult
{
    /** @param list<DogfoodDiagnostic> $diagnostics */
    public function __construct(
        public readonly ?string $failure,
        public readonly ?int $exitCode,
        public readonly array $diagnostics,
        public readonly float $milliseconds,
        public readonly ?int $analyzedFiles,
        public readonly string $analysisMode = 'runtime',
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
            'diagnostics' => $this->diagnostics,
        ];
    }
}
