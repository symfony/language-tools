<?php

namespace Symfony\Lsp\Index;

use Amp\Cancellation;
use Symfony\Lsp\Feature\Configuration\StaleConfigurationValidationSnapshotException;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\AnalysisSettingsRegistry;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectAnalysisSettings;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\TrustStatus;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;

/**
 * @phpstan-import-type ProjectRuntimeIndexStatus from ProjectIndexStatusRegistry
 *
 * @phpstan-type ProjectIndexCommandStatus array{root: string, environment: string, kernel: string|null, runtimeEnabled: bool, trusted: bool, source: array{state: string, error?: string}, runtime: ProjectRuntimeIndexStatus}
 */
final class IndexCommandHandler
{
    public const REFRESH_COMMAND = 'symfony.refreshIndex';
    public const STATUS_COMMAND = 'symfony.indexStatus';
    public const SWITCH_ENVIRONMENT_COMMAND = 'symfony.switchEnvironment';
    public const SWITCH_KERNEL_COMMAND = 'symfony.switchKernel';

    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly WorkspaceTrust $workspaceTrust,
        private readonly ApplicationSourceScanner $sourceScanner,
        private readonly RuntimeInitializerInterface $runtimeInitializer,
        private readonly ProjectIndexStatusRegistry $statuses,
        private readonly RuntimeConfiguration $configuration,
        private readonly AnalysisSettingsRegistry $settings,
        private readonly AnalysisSettings $analysisSettings,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<ProjectIndexCommandStatus>|null
     */
    public function execute(array $params, ?Cancellation $cancellation = null): ?array
    {
        $command = $params['command'] ?? null;
        if (!\is_string($command) || !\in_array($command, [self::REFRESH_COMMAND, self::STATUS_COMMAND, self::SWITCH_ENVIRONMENT_COMMAND, self::SWITCH_KERNEL_COMMAND], true)) {
            return null;
        }

        $projects = $this->selectedProjects($params);
        if (self::SWITCH_ENVIRONMENT_COMMAND === $command || self::SWITCH_KERNEL_COMMAND === $command) {
            $switchesEnvironment = self::SWITCH_ENVIRONMENT_COMMAND === $command;
            $value = $switchesEnvironment ? $this->environment($params) : $this->kernel($params);
            if (null === $value) {
                return null;
            }
            foreach ($projects as $project) {
                $cancellation?->throwIfRequested();
                if ($switchesEnvironment) {
                    $this->settings->setEnvironment($project, $value);
                } else {
                    $this->settings->setKernel($project, '' === $value ? null : $value);
                }
                if ($this->configuration->runtimeIndexing($project) && TrustStatus::Trusted === $this->workspaceTrust->status($project)) {
                    $this->initializeRuntime($project, RuntimeRefreshPlan::rebuild(), $cancellation);
                }
            }
        } elseif (self::REFRESH_COMMAND === $command) {
            foreach ($projects as $project) {
                $cancellation?->throwIfRequested();
                $this->sourceScanner->refreshProject($project, $cancellation);
                if ($this->configuration->runtimeIndexing($project) && TrustStatus::Trusted === $this->workspaceTrust->status($project)) {
                    $this->initializeRuntime($project, RuntimeRefreshPlan::reuse(), $cancellation);
                }
            }
        }

        return array_map(fn (Project $project): array => [
            ...$this->statuses->status($project),
            'environment' => $this->configuration->environment($project),
            'kernel' => $this->configuration->kernel($project),
            'runtimeEnabled' => $this->configuration->runtimeIndexing($project),
            'trusted' => TrustStatus::Trusted === $this->workspaceTrust->status($project),
        ], $projects);
    }

    private function initializeRuntime(Project $project, RuntimeRefreshPlan $plan, ?Cancellation $cancellation = null): void
    {
        while (true) {
            try {
                $this->runtimeInitializer->initialize($project, $plan, $cancellation);

                return;
            } catch (StaleConfigurationValidationSnapshotException $error) {
                $cancellation?->throwIfRequested();
                if (!$this->projects->contains($project)) {
                    throw $error;
                }
            }
        }
    }

    /** @param array<array-key, mixed> $params */
    private function environment(array $params): ?string
    {
        $value = $this->argument($params);

        return \is_string($value) ? $this->normalized(['environment' => $value])?->environment : null;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return string|null the selected kernel, an empty string to detect it again, or null when the request is invalid
     */
    private function kernel(array $params): ?string
    {
        $kernel = $this->argument($params);
        if ('' === $kernel) {
            return '';
        }

        return \is_string($kernel) ? $this->normalized(['kernel' => $kernel])?->kernel : null;
    }

    /** @param array<array-key, mixed> $params */
    private function argument(array $params): mixed
    {
        $arguments = $params['arguments'] ?? null;

        return \is_array($arguments) ? ($arguments[1] ?? null) : null;
    }

    /** @param array<string, string> $setting */
    private function normalized(array $setting): ?ProjectAnalysisSettings
    {
        try {
            return $this->analysisSettings->normalizeProject($setting);
        } catch (InvalidConfigurationException) {
            return null;
        }
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
            static fn (Project $project): bool => $root === $project->rootPath || $root === $project->rootUri,
        ));
    }
}
