<?php

namespace Symfony\Lsp\Check;

final class CheckProfile
{
    /**
     * @param array<string, float|null> $phasesMilliseconds
     * @param list<CheckProfileProject> $projects
     */
    public function __construct(
        public readonly float $totalMilliseconds,
        public readonly array $phasesMilliseconds,
        public readonly ?float $baselineMatchingMilliseconds,
        public readonly array $projects,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'totalMilliseconds' => $this->totalMilliseconds,
            'phasesMilliseconds' => $this->phasesMilliseconds,
            'baselineMatchingMilliseconds' => $this->baselineMatchingMilliseconds,
            'projects' => array_map(
                static fn (CheckProfileProject $project): array => $project->toArray(),
                $this->projects,
            ),
        ];
    }
}
