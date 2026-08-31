<?php

namespace Symfony\Lsp\Check;

/** @phpstan-import-type CheckError from CheckResult */
final class CheckReportView
{
    /**
     * @param list<CheckProjectResult>           $projects
     * @param array<string, CheckProjectResult>  $projectsById
     * @param list<CheckReportDiagnosticView>    $diagnostics
     * @param list<CheckReportBaselineEntryView> $staleBaseline
     * @param list<CheckError>                   $errors
     */
    public function __construct(
        public readonly string $version,
        public readonly bool $complete,
        public readonly array $projects,
        public readonly array $projectsById,
        public readonly array $diagnostics,
        public readonly array $staleBaseline,
        public readonly ?string $baselinePath,
        public readonly string $baselineMode,
        public readonly bool $strictBaseline,
        public readonly array $errors,
        public readonly CheckReportSummary $summary,
        public readonly int $exitCode,
    ) {
    }
}
