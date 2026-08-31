<?php

namespace Symfony\Lsp\Check;

final class BaselineMatcher
{
    /**
     * @param list<CheckDiagnostic> $diagnostics
     *
     * @return list<BaselineEntry>
     */
    public function entries(array $diagnostics): array
    {
        $occurrences = [];
        $entries = [];
        foreach ($diagnostics as $diagnostic) {
            $occurrence = ($occurrences[$diagnostic->fingerprint] ?? 0) + 1;
            $occurrences[$diagnostic->fingerprint] = $occurrence;
            $entries[] = new BaselineEntry(
                $diagnostic->project,
                $diagnostic->path,
                $diagnostic->code,
                $diagnostic->severityName(),
                $diagnostic->source,
                $diagnostic->message,
                $diagnostic->fingerprint,
                $occurrence,
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
    public function match(array $diagnostics, array $entries): array
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
