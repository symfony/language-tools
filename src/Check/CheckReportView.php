<?php

namespace Symfony\Lsp\Check;

final class CheckReportView
{
    /**
     * @param list<CheckReportDiagnosticView>    $diagnostics
     * @param list<CheckReportBaselineEntryView> $staleBaseline
     */
    public function __construct(
        public readonly CheckResult $result,
        public readonly array $diagnostics,
        public readonly array $staleBaseline,
        public readonly CheckReportSummary $summary,
        public readonly int $exitCode,
    ) {
    }
}
