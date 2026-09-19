<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Runtime\RuntimeMetadataException;

/**
 * @phpstan-import-type RuntimeMetadataSectionError from RuntimeMetadataException
 *
 * @phpstan-type CheckErrorCause array{class: string, message: string, sections?: non-empty-list<RuntimeMetadataSectionError>}
 * @phpstan-type CheckError array{category: string, message: string, project?: string, environment?: string, workspacePath?: string, provider?: string, cause?: CheckErrorCause}
 */
final class CheckResult
{
    /**
     * @param list<CheckProjectResult> $projects
     * @param list<CheckDiagnostic>    $diagnostics
     * @param list<BaselineEntry>      $staleBaseline
     * @param list<CheckError>         $errors
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
        public readonly ?CheckProfile $profile = null,
    ) {
    }
}
