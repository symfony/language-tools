<?php

namespace Symfony\Lsp\Check;

final class CheckReportViewBuilder
{
    public function __construct(private readonly CheckDiagnosticOccurrenceNumberer $occurrences)
    {
    }

    public function build(CheckResult $result, int $exitCode): CheckReportView
    {
        $projectsById = [];
        foreach ($result->projects as $project) {
            $projectsById[$project->id] = $project;
        }

        $diagnostics = [];
        $active = 0;
        foreach ($this->occurrences->number($result->diagnostics) as $occurrence) {
            $diagnostic = $occurrence->diagnostic;
            $project = $projectsById[$diagnostic->project] ?? null;
            $diagnostics[] = new CheckReportDiagnosticView(
                $diagnostic,
                $occurrence->number,
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
            $result->version,
            $result->complete,
            $result->projects,
            $projectsById,
            $diagnostics,
            $staleBaseline,
            $result->baselinePath,
            $result->baselineMode,
            $result->strictBaseline,
            $result->errors,
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
