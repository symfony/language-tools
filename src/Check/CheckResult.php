<?php

namespace Symfony\Lsp\Check;

final class CheckResult
{
    /**
     * @param list<CheckProjectResult>                                         $projects
     * @param list<CheckDiagnostic>                                            $diagnostics
     * @param list<BaselineEntry>                                              $staleBaseline
     * @param list<array{category: string, message: string, project?: string}> $errors
     */
    public function __construct(
        public readonly string $version,
        public readonly bool $complete,
        public readonly array $projects,
        public readonly array $diagnostics,
        public readonly array $staleBaseline,
        public readonly ?string $baselinePath,
        public readonly string $baselineMode,
        public readonly bool $strictBaseline,
        public readonly array $errors,
        public readonly int $blockingCount,
    ) {
    }
}
