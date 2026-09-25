<?php

namespace Symfony\Lsp\Check;

final class CheckReportViewBuilder
{
    public function build(CheckResult $result, int $exitCode): CheckReportView
    {
        $projectsById = [];
        foreach ($result->projects as $project) {
            $projectsById[$project->id] = $project;
        }

        $diagnostics = [];
        $active = 0;
        foreach ($result->diagnostics as $diagnostic) {
            $project = $projectsById[$diagnostic->project] ?? null;
            $diagnostics[] = new CheckReportDiagnosticView(
                $diagnostic,
                hash('sha256', $diagnostic->fingerprint."\0".$diagnostic->occurrence),
                strstr($diagnostic->code, '.', true) ?: $diagnostic->code,
                $project?->environment,
                $project?->mode,
            );
            if ('active' === $diagnostic->baselineState) {
                ++$active;
            }
        }

        $staleBaseline = array_map(
            static fn (BaselineEntry $entry): CheckReportBaselineEntryView => new CheckReportBaselineEntryView(
                $entry,
                '.' === $entry->project ? $entry->path : $entry->project.'/'.$entry->path,
            ),
            $result->staleBaseline,
        );

        return new CheckReportView(
            $result,
            $diagnostics,
            $staleBaseline,
            new CheckReportSummary(
                \count($diagnostics),
                $active,
                \count($diagnostics) - $active,
                \count($staleBaseline),
                $result->blockingCount,
            ),
            $exitCode,
        );
    }
}
