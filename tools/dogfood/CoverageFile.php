<?php

namespace Symfony\Lsp\Tools\Dogfood;

/**
 * Execution facts for one source file, unioned over every server process.
 */
final class CoverageFile
{
    /**
     * @param list<int> $executedLines
     * @param list<int> $unexecutedLines  executable lines no process reached
     * @param list<int> $unhitBranchLines
     */
    public function __construct(
        public readonly string $path,
        public readonly array $executedLines,
        public readonly array $unexecutedLines,
        public readonly int $branchCount,
        public readonly int $hitBranchCount,
        public readonly array $unhitBranchLines,
    ) {
    }

    public function isExecuted(): bool
    {
        return [] !== $this->executedLines;
    }

    public function executableLineCount(): int
    {
        return \count($this->executedLines) + \count($this->unexecutedLines);
    }

    /**
     * @return array{executedLines: list<int>, unexecutedLines: list<int>, branches: int, hitBranches: int, unhitBranchLines: list<int>}
     */
    public function toArray(): array
    {
        return [
            'executedLines' => $this->executedLines,
            'unexecutedLines' => $this->unexecutedLines,
            'branches' => $this->branchCount,
            'hitBranches' => $this->hitBranchCount,
            'unhitBranchLines' => $this->unhitBranchLines,
        ];
    }
}
