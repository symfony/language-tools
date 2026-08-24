<?php

namespace Symfony\Lsp\Check;

final class CheckReporter
{
    public function render(CheckResult $result, string $format): string
    {
        return match ($format) {
            'json' => $this->json($result),
            'github' => $this->github($result),
            default => $this->human($result),
        };
    }

    /** @param list<string> $codes */
    public function codes(array $codes, string $format): string
    {
        return match ($format) {
            'json' => json_encode([
                'schemaVersion' => 1,
                'diagnosticCodes' => $codes,
            ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n",
            'github' => implode('', array_map(
                fn (string $code): string => \sprintf('::notice title=Symfony diagnostic code::%s%s', $this->escapeData($code), \PHP_EOL),
                $codes,
            )),
            default => implode("\n", $codes)."\n",
        };
    }

    public function help(): string
    {
        return <<<'HELP'
Usage: symfony-lsp check [options] [files, directories or patterns]

Options:
  --format=human|json|github       Select the report format
  --workspace=PATH                 Set the workspace root
  --config=PATH                    Load a configuration file instead of .symfony-lsp.json
  --project-root=PATH              Select an explicit Symfony project root; repeatable
  --source-only                    Disable runtime indexing and application execution
  --php-command=JSON               Override the project PHP command argument list
  --container-project-root=PATH    Override the container-side project root
  --no-container-project-root      Run the project PHP command on the host
  --environment=NAME               Override the Symfony environment
  --debug, --no-debug              Enable or disable Symfony debug mode
  --runtime-indexing               Enable runtime indexing
  --no-runtime-indexing            Disable runtime indexing
  --bridge-timeout=SECONDS         Set each project bridge deadline
  --timeout=SECONDS                Set the complete check deadline; defaults to 600
  --translation-diagnostics        Enable missing-translation diagnostics
  --no-translation-diagnostics     Disable missing-translation diagnostics
  --fail-on=CODE,...               Restrict blocking diagnostics to selected codes
  --list-codes                     List supported diagnostic codes
  --baseline=PATH                  Match an occurrence-specific baseline
  --generate-baseline              Create a new baseline
  --refresh-baseline               Replace an existing baseline
  --strict-baseline                Fail when baseline entries become stale
  --help, -h                       Display this help

Runtime analysis executes application code. Use --source-only for untrusted code.
HELP;
    }

    private function human(CheckResult $result): string
    {
        $lines = [];
        foreach ($result->projects as $project) {
            $mode = 'runtime' === $project->mode
                ? 'runtime metadata'
                : 'source-only ('.$this->reason($project->modeReason).')';
            $lines[] = \sprintf(
                'Project %s: %s, environment %s, %s',
                $project->id,
                $mode,
                $project->environment,
                $project->complete ? 'complete' : 'incomplete',
            );
        }
        foreach ($result->diagnostics as $diagnostic) {
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
        foreach ($result->staleBaseline as $entry) {
            $lines[] = \sprintf(
                '%s:%s: stale baseline [%s] %s (occurrence %d)',
                $entry->project,
                $entry->path,
                $entry->code,
                $entry->message,
                $entry->occurrence,
            );
        }
        foreach ($result->errors as $error) {
            $lines[] = \sprintf(
                'ERROR%s: %s',
                isset($error['project']) ? ' ['.$error['project'].']' : '',
                $error['message'],
            );
        }

        $active = \count(array_filter($result->diagnostics, static fn (CheckDiagnostic $diagnostic): bool => 'active' === $diagnostic->baselineState));
        $matched = \count($result->diagnostics) - $active;
        $lines[] = \sprintf(
            'Summary: %d diagnostics, %d active, %d baseline matches, %d stale baseline entries, %d blocking',
            \count($result->diagnostics),
            $active,
            $matched,
            \count($result->staleBaseline),
            $result->blockingCount,
        );

        return implode("\n", $lines)."\n";
    }

    private function json(CheckResult $result): string
    {
        $active = \count(array_filter($result->diagnostics, static fn (CheckDiagnostic $diagnostic): bool => 'active' === $diagnostic->baselineState));

        return json_encode([
            'schemaVersion' => 1,
            'tool' => [
                'name' => 'Symfony Language Tools',
                'version' => $result->version,
            ],
            'complete' => $result->complete,
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
            ], $result->projects),
            'diagnostics' => array_map(static fn (CheckDiagnostic $diagnostic): array => [
                'project' => $diagnostic->project,
                'path' => $diagnostic->path,
                'workspacePath' => $diagnostic->workspacePath,
                'range' => [
                    'start' => ['line' => $diagnostic->startLine, 'character' => $diagnostic->startCharacter],
                    'end' => ['line' => $diagnostic->endLine, 'character' => $diagnostic->endCharacter],
                ],
                'severity' => $diagnostic->severityName(),
                'code' => $diagnostic->code,
                'source' => $diagnostic->source,
                'message' => $diagnostic->message,
                'baseline' => $diagnostic->baselineState,
            ], $result->diagnostics),
            'baseline' => [
                'path' => $result->baselinePath,
                'mode' => $result->baselineMode,
                'strict' => $result->strictBaseline,
                'stale' => array_map(static fn (BaselineEntry $entry): array => $entry->toArray(), $result->staleBaseline),
            ],
            'summary' => [
                'diagnostics' => \count($result->diagnostics),
                'active' => $active,
                'matched' => \count($result->diagnostics) - $active,
                'stale' => \count($result->staleBaseline),
                'blocking' => $result->blockingCount,
            ],
            'errors' => $result->errors,
        ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    }

    private function github(CheckResult $result): string
    {
        $lines = [];
        foreach ($result->projects as $project) {
            $message = !$project->complete
                ? \sprintf('Project %s analysis is incomplete.', $project->id)
                : ('runtime' === $project->mode
                    ? \sprintf('Project %s analyzed with runtime metadata in the %s environment.', $project->id, $project->environment)
                    : \sprintf('Project %s analyzed in source-only mode: %s.', $project->id, $this->reason($project->modeReason)));
            $lines[] = '::notice title=Symfony diagnostics::'.$this->escapeData($message);
        }
        foreach ($result->diagnostics as $diagnostic) {
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
                    max(1, $diagnostic->endCharacter),
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
        foreach ($result->staleBaseline as $entry) {
            $lines[] = \sprintf(
                '::warning file=%s,title=Stale Symfony diagnostic baseline::%s',
                $this->escapeProperty($this->entryWorkspacePath($entry)),
                $this->escapeData(\sprintf('[%s] %s (occurrence %d)', $entry->code, $entry->message, $entry->occurrence)),
            );
        }
        foreach ($result->errors as $error) {
            $lines[] = '::error title=Symfony diagnostics check::'.$this->escapeData($error['message']);
        }
        $lines[] = \sprintf(
            '::notice title=Symfony diagnostics summary::%d diagnostics, %d stale baseline entries, %d blocking.',
            \count($result->diagnostics),
            \count($result->staleBaseline),
            $result->blockingCount,
        );

        return implode("\n", $lines)."\n";
    }

    private function reason(?string $reason): string
    {
        return match ($reason) {
            'debug-disabled' => 'Symfony debug mode is disabled',
            'runtime-indexing-disabled' => 'runtime indexing is disabled',
            default => 'runtime analysis is unavailable',
        };
    }

    private function entryWorkspacePath(BaselineEntry $entry): string
    {
        return '.' === $entry->project ? $entry->path : $entry->project.'/'.$entry->path;
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
