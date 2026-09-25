<?php

namespace Symfony\Lsp\Check;

final class HumanCheckReportFormat implements CheckReportFormatInterface
{
    public function __construct(private readonly CheckErrorCauseRenderer $causes)
    {
    }

    public function name(): string
    {
        return 'human';
    }

    public function render(CheckReportView $view, bool $verbose): string
    {
        $lines = [];
        foreach ($view->projects as $project) {
            $mode = 'runtime' === $project->mode
                ? 'runtime metadata'
                : 'source-only ('.$project->sourceOnlyDescription().')';
            $lines[] = \sprintf(
                'Project %s: %s, environment %s, %s',
                $project->id,
                $mode,
                $project->environment,
                $project->complete ? 'complete' : 'incomplete',
            );
        }
        foreach ($view->diagnostics as $diagnosticView) {
            $diagnostic = $diagnosticView->diagnostic;
            $lines[] = \sprintf(
                '%s:%s:%d:%d: %s [%s] %s%s',
                $diagnostic->project,
                $diagnostic->path,
                $diagnostic->startLine + 1,
                $diagnostic->startCharacter + 1,
                $diagnostic->severityName(),
                $diagnostic->code,
                $diagnostic->message,
                'matched' === $diagnostic->baselineState ? ' (baseline)' : '',
            );
        }
        foreach ($view->staleBaseline as $entryView) {
            $entry = $entryView->entry;
            $lines[] = \sprintf(
                '%s:%s: stale baseline [%s] %s (occurrence %d)',
                $entry->project,
                $entry->path,
                $entry->code,
                $entry->message,
                $entry->occurrence,
            );
        }
        foreach ($view->errors as $error) {
            $lines[] = \sprintf(
                'ERROR%s: %s',
                isset($error['project']) ? ' ['.$error['project'].']' : '',
                $error['message'],
            );
            if (isset($error['cause'])) {
                array_push($lines, ...$verbose
                    ? $this->causes->lines($error['cause'], '  ')
                    : ['  Add --verbose to show the cause.']);
            }
        }

        $lines[] = \sprintf(
            'Summary: %d diagnostics, %d active, %d baseline matches, %d stale baseline entries, %d blocking',
            $view->summary->diagnostics,
            $view->summary->active,
            $view->summary->matched,
            $view->summary->stale,
            $view->summary->blocking,
        );

        return implode("\n", $lines)."\n";
    }

    public function codes(array $codes): string
    {
        return implode("\n", $codes)."\n";
    }
}
