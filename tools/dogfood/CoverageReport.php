<?php

namespace Symfony\Lsp\Tools\Dogfood;

/**
 * What the dogfooding runs executed, per file and in total.
 *
 * The numbers describe reach, not correctness: a covered line only means some
 * run walked through it.
 */
final class CoverageReport
{
    /**
     * @param array<string, CoverageFile> $files          keyed and ordered by path
     * @param string|null                 $sourceIdentity identity of the source tree the artifacts measured
     */
    public function __construct(
        public readonly array $files,
        public readonly int $artifactCount,
        public readonly ?string $sourceIdentity = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function uncoveredFiles(): array
    {
        $paths = [];
        foreach ($this->files as $path => $file) {
            if (!$file->isExecuted()) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    public function executedFileCount(): int
    {
        return \count(array_filter($this->files, static fn (CoverageFile $file): bool => $file->isExecuted()));
    }

    public function executedLineCount(): int
    {
        return array_sum(array_map(static fn (CoverageFile $file): int => \count($file->executedLines), $this->files));
    }

    public function executableLineCount(): int
    {
        return array_sum(array_map(static fn (CoverageFile $file): int => $file->executableLineCount(), $this->files));
    }

    public function branchCount(): int
    {
        return array_sum(array_map(static fn (CoverageFile $file): int => $file->branchCount, $this->files));
    }

    public function hitBranchCount(): int
    {
        return array_sum(array_map(static fn (CoverageFile $file): int => $file->hitBranchCount, $this->files));
    }

    /**
     * @return array{
     *     format: string,
     *     source: string|null,
     *     artifacts: int,
     *     totals: array{files: int, executedFiles: int, executableLines: int, executedLines: int, branches: int, hitBranches: int},
     *     uncoveredFiles: list<string>,
     *     files: array<string, array{executedLines: list<int>, unexecutedLines: list<int>, branches: int, hitBranches: int, unhitBranchLines: list<int>}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'format' => 'symfony-lsp-coverage-report/2',
            'source' => $this->sourceIdentity,
            'artifacts' => $this->artifactCount,
            'totals' => [
                'files' => \count($this->files),
                'executedFiles' => $this->executedFileCount(),
                'executableLines' => $this->executableLineCount(),
                'executedLines' => $this->executedLineCount(),
                'branches' => $this->branchCount(),
                'hitBranches' => $this->hitBranchCount(),
            ],
            'uncoveredFiles' => $this->uncoveredFiles(),
            'files' => array_map(static fn (CoverageFile $file): array => $file->toArray(), $this->files),
        ];
    }
}
