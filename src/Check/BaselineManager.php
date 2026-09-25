<?php

namespace Symfony\Lsp\Check;

final class BaselineManager
{
    public function __construct(private readonly BaselineRepository $repository)
    {
    }

    /**
     * @param list<CheckDiagnostic> $diagnostics
     *
     * @return array{diagnostics: list<CheckDiagnostic>, stale: list<BaselineEntry>, path: string|null}
     */
    public function apply(string $workspace, CheckOptions $options, array $diagnostics, bool $complete = true): array
    {
        if (null === $options->baselinePath) {
            return ['diagnostics' => $diagnostics, 'stale' => [], 'path' => null];
        }

        $file = $this->repository->resolve($workspace, $options->baselinePath);
        if (!$complete && !$this->repository->exists($file)) {
            return ['diagnostics' => $diagnostics, 'stale' => [], 'path' => null];
        }
        if (!$complete || 'none' === $options->baselineMode) {
            $entries = $this->repository->load($file);
        } else {
            $entries = $this->entries($diagnostics);
            if ('create' === $options->baselineMode) {
                $this->repository->create($file, $entries);
            } else {
                $this->repository->refresh($file, $entries);
            }
        }

        $result = $this->match($diagnostics, $entries);

        return [
            'diagnostics' => $result['diagnostics'],
            'stale' => $complete ? $result['stale'] : [],
            'path' => $file->workspacePath,
        ];
    }

    /**
     * @param list<CheckDiagnostic> $diagnostics
     *
     * @return list<BaselineEntry>
     */
    private function entries(array $diagnostics): array
    {
        $entries = [];
        foreach ($diagnostics as $diagnostic) {
            $entries[] = new BaselineEntry(
                $diagnostic->project,
                $diagnostic->path,
                $diagnostic->code,
                $diagnostic->severityName(),
                $diagnostic->source,
                $diagnostic->message,
                $diagnostic->fingerprint,
                $diagnostic->occurrence,
            );
        }

        return $entries;
    }

    /**
     * @param list<CheckDiagnostic> $diagnostics
     * @param list<BaselineEntry>   $entries
     *
     * @return array{diagnostics: list<CheckDiagnostic>, stale: list<BaselineEntry>}
     */
    private function match(array $diagnostics, array $entries): array
    {
        $remaining = [];
        foreach ($entries as $entry) {
            $remaining[$entry->fingerprint][] = $entry;
        }
        $classified = [];
        foreach ($diagnostics as $diagnostic) {
            $matches = $remaining[$diagnostic->fingerprint] ?? [];
            $entry = array_shift($matches);
            $remaining[$diagnostic->fingerprint] = $matches;
            $classified[] = null === $entry ? $diagnostic : $diagnostic->withBaselineState('matched');
        }

        $stale = [];
        foreach ($remaining as $entriesForFingerprint) {
            array_push($stale, ...$entriesForFingerprint);
        }
        usort($stale, static fn (BaselineEntry $left, BaselineEntry $right): int => [
            $left->project,
            $left->path,
            $left->code,
            $left->message,
            $left->occurrence,
        ] <=> [
            $right->project,
            $right->path,
            $right->code,
            $right->message,
            $right->occurrence,
        ]);

        return ['diagnostics' => $classified, 'stale' => $stale];
    }
}
