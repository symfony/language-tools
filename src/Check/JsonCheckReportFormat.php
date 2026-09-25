<?php

namespace Symfony\Lsp\Check;

final class JsonCheckReportFormat implements CheckReportFormatInterface
{
    public function name(): string
    {
        return 'json';
    }

    public function render(CheckReportView $view, bool $verbose): string
    {
        return json_encode([
            'schemaVersion' => 1,
            'tool' => [
                'name' => 'Symfony Language Tools',
                'version' => $view->version,
            ],
            'complete' => $view->complete,
            'coordinates' => [
                'lineBase' => 0,
                'characterBase' => 0,
                'characterEncoding' => 'utf-16',
                'endExclusive' => true,
            ],
            'projects' => array_map(static fn (CheckProjectResult $project): array => [
                'id' => $project->id,
                'environment' => $project->environment,
                'analysis' => [
                    'mode' => $project->mode,
                    'reason' => $project->modeReason,
                ],
                'source' => $project->source,
                'runtime' => $project->runtime,
                'complete' => $project->complete,
            ], $view->projects),
            ...(null === $view->profile ? [] : ['profile' => $view->profile->toArray()]),
            'diagnostics' => array_map(static fn (CheckReportDiagnosticView $diagnosticView): array => [
                'project' => $diagnosticView->diagnostic->project,
                'path' => $diagnosticView->diagnostic->path,
                'workspacePath' => $diagnosticView->diagnostic->workspacePath,
                'range' => [
                    'start' => ['line' => $diagnosticView->diagnostic->startLine, 'character' => $diagnosticView->diagnostic->startCharacter],
                    'end' => ['line' => $diagnosticView->diagnostic->endLine, 'character' => $diagnosticView->diagnostic->endCharacter],
                ],
                'severity' => $diagnosticView->diagnostic->severityName(),
                'code' => $diagnosticView->diagnostic->code,
                'source' => $diagnosticView->diagnostic->source,
                'message' => $diagnosticView->diagnostic->message,
                'baseline' => $diagnosticView->diagnostic->baselineState,
                'provenance' => [
                    'feature' => $diagnosticView->feature,
                    'provider' => $diagnosticView->diagnostic->provider,
                    'environment' => $diagnosticView->environment,
                    'analysisMode' => $diagnosticView->analysisMode,
                ],
            ], $view->diagnostics),
            'baseline' => [
                'path' => $view->baselinePath,
                'mode' => $view->baselineMode,
                'strict' => $view->strictBaseline,
                'stale' => array_map(static fn (CheckReportBaselineEntryView $entryView): array => $entryView->entry->toArray(), $view->staleBaseline),
            ],
            'summary' => $view->summary->toArray(),
            'errors' => $view->errors,
        ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    }

    public function codes(array $codes): string
    {
        return json_encode([
            'schemaVersion' => 1,
            'diagnosticCodes' => $codes,
        ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n";
    }
}
