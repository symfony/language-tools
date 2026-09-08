<?php

namespace Symfony\Lsp\Tools\Dogfood;

/**
 * @phpstan-type ScenarioExpectation array{equals?: list<string>, includes?: list<string>, excludes?: list<string>}
 * @phpstan-type ScenarioExpectations array<string, ScenarioExpectation>
 * @phpstan-type ScenarioEdit array{before: string, after: string, file: string, expect: ScenarioExpectations, anchor?: string, offset?: int, applyCodeAction?: string, afterFix?: ScenarioExpectations}
 * @phpstan-type Scenario array{id: string, file: string, anchor: string, offset: int, expect: ScenarioExpectations, newName?: string, edit?: ScenarioEdit}
 * @phpstan-type ScenarioDiagnostic array{path: string, code: string, severity: string, kind: string, reason: string, messageHash: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}
 */
final class ScenarioManifest
{
    /**
     * @param list<Scenario>           $scenarios
     * @param list<ScenarioDiagnostic> $diagnostics
     */
    public function __construct(
        public readonly string $revision,
        public readonly array $scenarios,
        public readonly array $diagnostics,
    ) {
    }
}
