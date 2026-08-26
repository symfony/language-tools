<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectStateInterface;

final class RuntimeConfiguration implements ProjectStateInterface
{
    /** @var array<string, mixed> */
    private array $initializationSettings = [];

    /** @var list<string> */
    private array $projectRoots = [];

    /** @var array<string, array<string, mixed>> */
    private array $projectSettings = [];

    private readonly AnalysisSettings $analysisSettings;

    /** @var non-empty-list<string> */
    private readonly array $defaultPhpCommand;

    /** @param array<array-key, mixed> $defaultPhpCommand */
    public function __construct(?AnalysisSettings $analysisSettings = null, array $defaultPhpCommand = ['php'])
    {
        $this->analysisSettings = $analysisSettings ?? new AnalysisSettings();
        $settings = $this->analysisSettings->normalizeProject(
            ['phpCommand' => $defaultPhpCommand],
            context: 'default runtime',
        );
        /** @var non-empty-list<string> $validatedDefaultPhpCommand */
        $validatedDefaultPhpCommand = $settings['phpCommand'];
        $this->defaultPhpCommand = $validatedDefaultPhpCommand;
    }

    /** @param array<array-key, mixed> $initializationOptions */
    public function configure(array $initializationOptions): void
    {
        $this->initializationSettings = [
            ...$this->initializationSettings,
            ...$this->analysisSettings->normalizeProject($initializationOptions, false),
        ];

        $projectRoots = $initializationOptions['projectRoots'] ?? null;
        if (\is_array($projectRoots) && array_is_list($projectRoots)) {
            $validated = [];
            foreach ($projectRoots as $root) {
                if (!\is_string($root) || '' === $root) {
                    $validated = [];
                    break;
                }
                $validated[] = $root;
            }
            if ([] !== $validated || [] === $projectRoots) {
                $this->projectRoots = $validated;
            }
        }
    }

    /** @param array<array-key, mixed> $settings */
    public function configureProject(Project $project, array $settings): void
    {
        $this->projectSettings[$project->rootPath()] = $this->analysisSettings->normalizeProject($settings, false);
    }

    public function setEnvironment(Project $project, string $environment): void
    {
        $settings = $this->projectSettings[$project->rootPath()] ?? [];
        $settings['environment'] = $environment;
        $this->configureProject($project, $settings);
    }

    public function removeProject(Project $project): void
    {
        unset($this->projectSettings[$project->rootPath()]);
    }

    /** @return array<string, mixed> */
    public function initializationSettings(): array
    {
        return $this->initializationSettings;
    }

    /** @return non-empty-list<string> */
    public function phpCommand(?Project $project = null): array
    {
        $command = $this->setting($project, 'phpCommand', $this->defaultPhpCommand);
        if (!\is_array($command) || [] === $command || !array_is_list($command)) {
            return $this->defaultPhpCommand;
        }
        foreach ($command as $argument) {
            if (!\is_string($argument) || '' === $argument) {
                return $this->defaultPhpCommand;
            }
        }

        return $command;
    }

    public function containerProjectRoot(?Project $project = null): ?string
    {
        $root = $this->setting($project, 'containerProjectRoot', null);

        return \is_string($root) ? $root : null;
    }

    public function environment(?Project $project = null): string
    {
        $environment = $this->setting($project, 'environment', 'dev');

        return \is_string($environment) ? $environment : 'dev';
    }

    public function debug(?Project $project = null): bool
    {
        return true === $this->setting($project, 'debug', true);
    }

    public function runtimeIndexingRequested(?Project $project = null): bool
    {
        return true === $this->setting($project, 'runtimeIndexing', true);
    }

    public function runtimeIndexing(?Project $project = null): bool
    {
        return $this->debug($project) && $this->runtimeIndexingRequested($project);
    }

    public function sourceOnlyReason(Project $project): ?string
    {
        if (!$this->debug($project)) {
            return 'debug-disabled';
        }
        if (!$this->runtimeIndexingRequested($project)) {
            return 'runtime-indexing-disabled';
        }

        return null;
    }

    public function bridgeTimeout(?Project $project = null): float
    {
        $timeout = $this->setting($project, 'bridgeTimeout', 300.0);

        return \is_float($timeout) ? $timeout : 300.0;
    }

    /** @return list<string> */
    public function projectRoots(): array
    {
        return $this->projectRoots;
    }

    private function setting(?Project $project, string $name, mixed $default): mixed
    {
        if (null !== $project) {
            $settings = $this->projectSettings[$project->rootPath()] ?? [];
            if (\array_key_exists($name, $settings)) {
                return $settings[$name];
            }
        }

        return \array_key_exists($name, $this->initializationSettings)
            ? $this->initializationSettings[$name]
            : $default;
    }
}
