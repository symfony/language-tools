<?php

namespace Symfony\Lsp\Check;

/**
 * @phpstan-import-type CheckError from CheckResult
 *
 * @phpstan-type ProjectStatus array{root: string, source: array{state: string, error?: string}, runtime: array{state: string, error?: string, stage?: string, lastSuccessfulAt?: string}}
 */
final class CheckProjectAnalysis
{
    /**
     * @param list<CheckError>             $errors
     * @param array<string, string>        $preparedHashes
     * @param array<string, string>        $preparedTexts
     * @param array<string, true>          $diagnosableProjects
     * @param array<string, bool>          $completeProjects
     * @param array<string, ProjectStatus> $statuses
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
