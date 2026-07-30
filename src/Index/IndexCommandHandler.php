<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\TrustStatus;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;

final class IndexCommandHandler
{
    public const REFRESH_COMMAND = 'symfony.refreshIndex';
    public const STATUS_COMMAND = 'symfony.indexStatus';

    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly WorkspaceTrust $workspaceTrust,
        private readonly ApplicationSourceScanner $sourceScanner,
        private readonly RuntimeInitializerInterface $runtimeInitializer,
        private readonly ProjectIndexStatusRegistry $statuses,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array{root: string, source: array{state: string, error?: string}, runtime: array{state: string, error?: string}}>|null
     */
    public function execute(array $params): ?array
    {
        $command = $params['command'] ?? null;
        if (!\is_string($command) || !\in_array($command, [self::REFRESH_COMMAND, self::STATUS_COMMAND], true)) {
            return null;
        }

        $projects = $this->selectedProjects($params);
        if (self::REFRESH_COMMAND === $command) {
            foreach ($projects as $project) {
                $this->sourceScanner->refreshProject($project);
                if (TrustStatus::Trusted === $this->workspaceTrust->status($project)) {
                    $this->runtimeInitializer->initialize($project);
                }
            }
        }

        return array_map($this->statuses->status(...), $projects);
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<Project>
     */
    private function selectedProjects(array $params): array
    {
        $arguments = $params['arguments'] ?? null;
        $root = \is_array($arguments) && \is_string($arguments[0] ?? null) ? $arguments[0] : null;
        if (null === $root) {
            return $this->projects->all();
        }

        return array_values(array_filter(
            $this->projects->all(),
            static fn (Project $project): bool => $root === $project->rootPath() || $root === $project->rootUri(),
        ));
    }
}
