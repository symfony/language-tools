<?php

namespace Symfony\Lsp\Check;

final class CheckDiagnosticOccurrenceNumberer
{
    /**
     * @param list<CheckDiagnostic> $diagnostics
     *
     * @return list<CheckDiagnosticOccurrence>
     */
    public function number(array $diagnostics): array
    {
        $counts = [];
        $numbered = [];
        foreach ($diagnostics as $diagnostic) {
            $number = ($counts[$diagnostic->fingerprint] ?? 0) + 1;
            $counts[$diagnostic->fingerprint] = $number;
            $numbered[] = new CheckDiagnosticOccurrence($diagnostic, $number);
        }

        return $numbered;
    }
}
