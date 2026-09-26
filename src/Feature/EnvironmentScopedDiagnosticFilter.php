<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Runtime\EnvironmentScopeResolver;

final class EnvironmentScopedDiagnosticFilter
{
    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly EnvironmentScopeResolver $environments,
        private readonly DiagnosticCodeRegistry $diagnosticCodes,
    ) {
    }

    /**
     * @param list<CollectedDiagnostic> $diagnostics
     *
     * @return list<CollectedDiagnostic>
     */
    public function filter(string $uri, array $diagnostics): array
    {
        if ([] === $diagnostics || !$this->excludesDocument($uri)) {
            return $diagnostics;
        }

        return array_values(array_filter($diagnostics, function (CollectedDiagnostic $diagnostic): bool {
            $code = $diagnostic->diagnostic['code'] ?? null;

            return !\is_string($code) || DiagnosticCodeScope::SelectedEnvironment !== $this->diagnosticCodes->scope($code);
        }));
    }

    private function excludesDocument(string $uri): bool
    {
        $project = $this->projects->forDocumentUri($uri);

        return null !== $project && !$this->environments->includesDocument($project, $uri);
    }
}
