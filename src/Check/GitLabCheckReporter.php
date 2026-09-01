<?php

namespace Symfony\Lsp\Check;

final class GitLabCheckReporter
{
    public function render(CheckReportView $view): string
    {
        $issues = [];
        foreach ($view->diagnostics as $diagnosticView) {
            $diagnostic = $diagnosticView->diagnostic;
            if ('matched' === $diagnostic->baselineState) {
                continue;
            }

            $issues[] = [
                'description' => $diagnostic->message,
                'check_name' => $diagnostic->code,
                'fingerprint' => $diagnosticView->occurrenceFingerprint,
                'severity' => match ($diagnostic->severity) {
                    1 => 'major',
                    2 => 'minor',
                    default => 'info',
                },
                'location' => [
                    'path' => $diagnostic->workspacePath,
                    'lines' => ['begin' => $diagnostic->startLine + 1],
                ],
            ];
        }

        return json_encode($issues, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    }

    public function codes(): string
    {
        return "[]\n";
    }
}
