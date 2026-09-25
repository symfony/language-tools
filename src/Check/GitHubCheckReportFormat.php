<?php

namespace Symfony\Lsp\Check;

final class GitHubCheckReportFormat implements CheckReportFormatInterface
{
    public function name(): string
    {
        return 'github';
    }

    public function render(CheckReportView $view, bool $verbose): string
    {
        $lines = [];
        foreach ($view->result->projects as $project) {
            $message = !$project->complete
                ? \sprintf('Project %s analysis is incomplete.', $project->id)
                : ('runtime' === $project->mode
                    ? \sprintf('Project %s analyzed with runtime metadata in the %s environment.', $project->id, $project->environment)
                    : \sprintf('Project %s analyzed in source-only mode: %s.', $project->id, $project->sourceOnlyDescription()));
            $lines[] = '::notice title=Symfony diagnostics::'.$this->escapeData($message);
        }
        foreach ($view->diagnostics as $diagnosticView) {
            $diagnostic = $diagnosticView->diagnostic;
            $level = match ($diagnostic->severity) {
                1 => 'error',
                2 => 'warning',
                default => 'notice',
            };
            $title = $diagnostic->code.('matched' === $diagnostic->baselineState ? ' (baseline)' : '');
            $location = $diagnostic->startLine === $diagnostic->endLine
                ? \sprintf(
                    'file=%s,line=%d,col=%d,endLine=%d,endColumn=%d',
                    $this->escapeProperty($diagnostic->workspacePath),
                    $diagnostic->startLine + 1,
                    $diagnostic->startCharacter + 1,
                    $diagnostic->endLine + 1,
                    max($diagnostic->startCharacter + 1, $diagnostic->endCharacter),
                )
                : \sprintf(
                    'file=%s,line=%d,endLine=%d',
                    $this->escapeProperty($diagnostic->workspacePath),
                    $diagnostic->startLine + 1,
                    $diagnostic->endLine + 1,
                );
            $lines[] = \sprintf(
                '::%s %s,title=%s::%s',
                $level,
                $location,
                $this->escapeProperty($title),
                $this->escapeData($diagnostic->message),
            );
        }
        foreach ($view->staleBaseline as $entryView) {
            $lines[] = \sprintf(
                '::warning file=%s,title=Stale Symfony diagnostic baseline::%s',
                $this->escapeProperty($entryView->workspacePath),
                $this->escapeData(\sprintf('[%s] %s (occurrence %d)', $entryView->entry->code, $entryView->entry->message, $entryView->entry->occurrence)),
            );
        }
        foreach ($view->result->errors as $error) {
            $lines[] = '::error title=Symfony diagnostics check::'.$this->escapeData($error['message']);
        }
        $lines[] = \sprintf(
            '::notice title=Symfony diagnostics summary::%d diagnostics, %d stale baseline entries, %d blocking.',
            $view->summary->diagnostics,
            $view->summary->stale,
            $view->summary->blocking,
        );

        return implode("\n", $lines)."\n";
    }

    public function codes(array $codes): string
    {
        return implode('', array_map(
            fn (string $code): string => \sprintf('::notice title=Symfony diagnostic code::%s%s', $this->escapeData($code), \PHP_EOL),
            $codes,
        ));
    }

    private function escapeData(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private function escapeProperty(string $value): string
    {
        return str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $value);
    }
}
