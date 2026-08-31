<?php

namespace Symfony\Lsp\Check;

final class GitLabCheckReporter
{
    public function render(CheckReportView $view): string
    {
        return json_encode(array_map(static function (CheckReportDiagnosticView $diagnosticView): array {
            $diagnostic = $diagnosticView->diagnostic;

            return [
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
        }, $view->diagnostics), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    }

    public function codes(): string
    {
        return "[]\n";
    }
}
