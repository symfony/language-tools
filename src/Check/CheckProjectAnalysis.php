<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Index\ProjectIndexStatusRegistry;

/**
 * @phpstan-import-type CheckError from CheckResult
 * @phpstan-import-type ProjectIndexStatus from ProjectIndexStatusRegistry
 */
final class CheckProjectAnalysis
{
    /**
     * @param list<CheckError>                  $errors
     * @param array<string, string>             $preparedHashes
     * @param array<string, string>             $preparedTexts
     * @param array<string, true>               $diagnosableProjects
     * @param array<string, bool>               $completeProjects
     * @param array<string, ProjectIndexStatus> $statuses
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $preparedHashes,
        public readonly array $preparedTexts,
        public readonly array $diagnosableProjects,
        public readonly array $completeProjects,
        public readonly array $statuses,
        public readonly bool $canceled,
    ) {
    }
}
