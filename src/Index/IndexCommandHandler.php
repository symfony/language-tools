<?php

namespace Symfony\Lsp\Index;

use Amp\Cancellation;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\TrustStatus;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshMode;

final class IndexCommandHandler
{
    public const REFRESH_COMMAND = 'symfony.refreshIndex';
    public const STATUS_COMMAND = 'symfony.indexStatus';
    public const SWITCH_ENVIRONMENT_COMMAND = 'symfony.switchEnvironment';

    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly WorkspaceTrust $workspaceTrust,
        private readonly ApplicationSourceScanner $sourceScanner,
        private readonly RuntimeInitializerInterface $runtimeInitializer,
        private readonly ProjectIndexStatusRegistry $statuses,
        private readonly RuntimeConfiguration $configuration,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array{root: string, environment: string, runtimeEnabled: bool, trusted: bool, source: array{state: string, error?: string}, runtime: array{state: string, error?: string}}>|null
     */
    public function execute(array $params, ?Cancellation $cancellation = null): ?array
    {
        $command = $params['command'] ?? null;
        if (!\is_string($command) || !\in_array($command, [self::REFRESH_COMMAND, self::STATUS_COMMAND, self::SWITCH_ENVIRONMENT_COMMAND], true)) {
            return null;
        }

        $projects = $this->selectedProjects($params);
        if (self::SWITCH_ENVIRONMENT_COMMAND === $command) {
            $environment = $this->environment($params);
            if (null === $environment) {
                return null;
            }
            foreach ($projects as $project) {
                $cancellation?->throwIfRequested();
                $this->configuration->setEnvironment($project, $environment);
                if ($this->configuration->runtimeIndexing($project) && TrustStatus::Trusted === $this->workspaceTrust->status($project)) {
                    $this->runtimeInitializer->initialize($project, RuntimeRefreshMode::Clear, $cancellation);
                }
            }
        } elseif (self::REFRESH_COMMAND === $command) {
            foreach ($projects as $project) {
                $cancellation?->throwIfRequested();
                $this->sourceScanner->refreshProject($project, $cancellation);
                if ($this->configuration->runtimeIndexing($project) && TrustStatus::Trusted === $this->workspaceTrust->status($project)) {
                    $this->runtimeInitializer->initialize($project, cancellation: $cancellation);
                }
            }
        }

        return array_map(fn (Project $project): array => [
            ...$this->statuses->status($project),
            'environment' => $this->configuration->environment($project),
            'runtimeEnabled' => $this->configuration->runtimeIndexing($project),
            'trusted' => TrustStatus::Trusted === $this->workspaceTrust->status($project),
        ], $projects);
    }

    /** @param array<array-key, mixed> $params */
    private function environment(array $params): ?string
    {
        $arguments = $params['arguments'] ?? null;
        $environment = \is_array($arguments) ? ($arguments[1] ?? null) : null;

        return \is_string($environment) && preg_match('/^[A-Za-z0-9_.-]+$/', $environment) ? $environment : null;
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
