<?php

namespace Symfony\Lsp\Check;

final class SarifCheckReporter
{
    private const SCHEMA = 'https://docs.oasis-open.org/sarif/sarif/v2.1.0/errata01/os/schemas/sarif-schema-2.1.0.json';

    public function __construct(
        private readonly DiagnosticCodeRegistry $diagnosticCodes,
        private readonly string $version,
    ) {
    }

    public function render(CheckResult $result, int $exitCode): string
    {
        $projects = [];
        foreach ($result->projects as $project) {
            $projects[$project->id] = $project;
        }

        $configurationNotifications = array_map(
            fn (BaselineEntry $entry): array => $this->staleBaselineNotification($entry, $result->strictBaseline),
            $result->staleBaseline,
        );
        $executionNotifications = [];
        foreach ($result->errors as $error) {
            $notification = $this->errorNotification($error);
            if ('invocation' === $error['category']) {
                $configurationNotifications[] = $notification;
            } else {
                $executionNotifications[] = $notification;
            }
        }

        $invocation = [
            'executionSuccessful' => $result->complete,
            'exitCode' => $exitCode,
        ];
        if ([] !== $configurationNotifications) {
            $invocation['toolConfigurationNotifications'] = $configurationNotifications;
        }
        if ([] !== $executionNotifications) {
            $invocation['toolExecutionNotifications'] = $executionNotifications;
        }

        return $this->encode([[
            'tool' => ['driver' => $this->driver()],
            'columnKind' => 'utf16CodeUnits',
            'invocations' => [$invocation],
            'results' => $this->results($result->diagnostics, $projects),
            'properties' => [
                'symfonyLsp.complete' => $result->complete,
                'symfonyLsp.projects' => array_map($this->project(...), $result->projects),
                'symfonyLsp.baseline' => [
                    'path' => $result->baselinePath,
                    'mode' => $result->baselineMode,
                    'strict' => $result->strictBaseline,
                ],
                'symfonyLsp.summary' => $this->summary($result),
            ],
        ]]);
    }

    /** @param list<string> $codes */
    public function codes(array $codes): string
    {
        return $this->encode([[
            'tool' => ['driver' => $this->driver($codes)],
            'columnKind' => 'utf16CodeUnits',
            'invocations' => [[
                'executionSuccessful' => true,
                'exitCode' => 0,
            ]],
            'results' => [],
        ]]);
    }

    /**
     * @param list<CheckDiagnostic>             $diagnostics
     * @param array<string, CheckProjectResult> $projects
     *
     * @return list<array<string, mixed>>
     */
    private function results(array $diagnostics, array $projects): array
    {
        $rules = array_flip($this->codesList());
        $occurrences = [];
        $results = [];
        foreach ($diagnostics as $diagnostic) {
            $occurrence = ($occurrences[$diagnostic->fingerprint] ?? 0) + 1;
            $occurrences[$diagnostic->fingerprint] = $occurrence;
            $project = $projects[$diagnostic->project] ?? null;
            $properties = [
                'symfonyLsp.project' => $diagnostic->project,
                'symfonyLsp.projectPath' => $diagnostic->path,
                'symfonyLsp.source' => $diagnostic->source,
                'symfonyLsp.feature' => strstr($diagnostic->code, '.', true) ?: $diagnostic->code,
                'symfonyLsp.provider' => $diagnostic->provider,
                'symfonyLsp.environment' => $project?->environment,
                'symfonyLsp.analysisMode' => $project?->mode,
                'symfonyLsp.baselineState' => $diagnostic->baselineState,
            ];
            $properties = array_filter($properties, static fn (mixed $value): bool => null !== $value);
            $result = [
                'ruleId' => $diagnostic->code,
                'ruleIndex' => $rules[$diagnostic->code],
                'level' => $this->level($diagnostic->severity),
                'message' => ['text' => $diagnostic->message],
                'locations' => [[
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => $this->uri($diagnostic->workspacePath)],
                        'region' => [
                            'startLine' => $diagnostic->startLine + 1,
                            'startColumn' => $diagnostic->startCharacter + 1,
                            'endLine' => $diagnostic->endLine + 1,
                            'endColumn' => $diagnostic->endCharacter + 1,
                        ],
                    ],
                ]],
                'partialFingerprints' => [
                    'symfonyLsp/v1' => hash('sha256', $diagnostic->fingerprint."\0".$occurrence),
                ],
                'properties' => $properties,
            ];
            if ('matched' === $diagnostic->baselineState) {
                $result['suppressions'] = [[
                    'kind' => 'external',
                    'status' => 'accepted',
                    'justification' => 'Matched the configured Symfony diagnostics baseline.',
                ]];
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * @param list<string>|null $codes
     *
     * @return array<string, mixed>
     */
    private function driver(?array $codes = null): array
    {
        $codes ??= $this->codesList();
        sort($codes);

        return [
            'name' => 'Symfony Language Tools',
            'version' => $this->version,
            'rules' => array_map($this->rule(...), $codes),
        ];
    }

    /** @return array<string, mixed> */
    private function rule(string $code): array
    {
        $feature = strstr($code, '.', true) ?: $code;
        $description = ucfirst(str_replace('_', ' ', preg_replace('/\./', ': ', $code, 1) ?? $code));

        return [
            'id' => $code,
            'name' => $code,
            'shortDescription' => ['text' => $description],
            'properties' => ['tags' => ['symfony', $feature]],
        ];
    }

    /** @return array<string, mixed> */
    private function staleBaselineNotification(BaselineEntry $entry, bool $strict): array
    {
        return [
            'descriptor' => ['id' => 'symfony.check.stale_baseline'],
            'level' => $strict ? 'error' : 'warning',
            'message' => ['text' => \sprintf('Stale baseline entry [%s] %s (occurrence %d).', $entry->code, $entry->message, $entry->occurrence)],
            'locations' => [[
                'physicalLocation' => [
                    'artifactLocation' => ['uri' => $this->uri($this->workspacePath($entry->project, $entry->path))],
                ],
            ]],
            'properties' => [
                'symfonyLsp.project' => $entry->project,
                'symfonyLsp.ruleId' => $entry->code,
                'symfonyLsp.fingerprint' => $entry->fingerprint,
                'symfonyLsp.occurrence' => $entry->occurrence,
            ],
        ];
    }

    /**
     * @param array{category: string, message: string, project?: string, environment?: string, workspacePath?: string, provider?: string, cause?: array{class: string, message: string}} $error
     *
     * @return array<string, mixed>
     */
    private function errorNotification(array $error): array
    {
        $notification = [
            'descriptor' => ['id' => 'symfony.check.'.$error['category']],
            'level' => 'error',
            'message' => ['text' => $error['message']],
            'properties' => array_filter([
                'symfonyLsp.category' => $error['category'],
                'symfonyLsp.project' => $error['project'] ?? null,
                'symfonyLsp.environment' => $error['environment'] ?? null,
                'symfonyLsp.provider' => $error['provider'] ?? null,
            ], static fn (mixed $value): bool => null !== $value),
        ];
        if (isset($error['workspacePath'])) {
            $notification['locations'] = [[
                'physicalLocation' => [
                    'artifactLocation' => ['uri' => $this->uri($error['workspacePath'])],
                ],
            ]];
        }
        if (isset($error['cause'])) {
            $notification['exception'] = [
                'kind' => $error['cause']['class'],
                'message' => $error['cause']['message'],
            ];
        }

        return $notification;
    }

    /** @return array<string, mixed> */
    private function project(CheckProjectResult $project): array
    {
        $runtime = ['state' => $project->runtime['state']];
        if (isset($project->runtime['stage'])) {
            $runtime['stage'] = $project->runtime['stage'];
        }

        return [
            'id' => $project->id,
            'environment' => $project->environment,
            'analysis' => ['mode' => $project->mode, 'reason' => $project->modeReason],
            'source' => ['state' => $project->source['state']],
            'runtime' => $runtime,
            'complete' => $project->complete,
        ];
    }

    /** @return array{diagnostics: int, active: int, matched: int, stale: int, blocking: int} */
    private function summary(CheckResult $result): array
    {
        $active = \count(array_filter($result->diagnostics, static fn (CheckDiagnostic $diagnostic): bool => 'active' === $diagnostic->baselineState));

        return [
            'diagnostics' => \count($result->diagnostics),
            'active' => $active,
            'matched' => \count($result->diagnostics) - $active,
            'stale' => \count($result->staleBaseline),
            'blocking' => $result->blockingCount,
        ];
    }

    /** @return list<string> */
    private function codesList(): array
    {
        $codes = $this->diagnosticCodes->all();
        sort($codes);

        return $codes;
    }

    private function level(int $severity): string
    {
        return match ($severity) {
            1 => 'error',
            2 => 'warning',
            default => 'note',
        };
    }

    private function uri(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', str_replace('\\', '/', $path))));
    }

    private function workspacePath(string $project, string $path): string
    {
        return '.' === $project ? $path : $project.'/'.$path;
    }

    /** @param list<array<string, mixed>> $runs */
    private function encode(array $runs): string
    {
        return json_encode([
            '$schema' => self::SCHEMA,
            'version' => '2.1.0',
            'runs' => $runs,
        ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    }
}
