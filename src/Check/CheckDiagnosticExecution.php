<?php

namespace Symfony\Lsp\Check;

/** @phpstan-import-type CheckError from CheckResult */
final class CheckDiagnosticExecution
{
    /**
     * @param list<CheckDiagnostic> $diagnostics
     * @param list<CheckError>      $errors
     * @param array<string, true>   $incompleteProjects
     */
    public function __construct(
        public readonly array $diagnostics,
        public readonly array $errors,
        public readonly array $incompleteProjects,
        public readonly bool $canceled,
    ) {
    }
}
