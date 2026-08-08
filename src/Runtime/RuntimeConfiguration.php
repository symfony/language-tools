<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\Project;

final class RuntimeConfiguration
{
    /** @var non-empty-list<string> */
    private array $phpCommand = ['php'];
    private string $consolePath = 'bin/console';
    private string $environment = 'dev';
    private bool $debug = true;
    private bool $runtimeIndexing = true;

    /** @var list<string> */
    private array $projectRoots = [];

    /** @var array<string, array<array-key, mixed>> */
    private array $projectSettings = [];

    /** @param array<array-key, mixed> $initializationOptions */
    public function configure(array $initializationOptions): void
    {
        $phpCommand = $initializationOptions['phpCommand'] ?? null;
        if (\is_array($phpCommand) && [] !== $phpCommand) {
            $validatedCommand = [];
            foreach ($phpCommand as $argument) {
                if (!\is_string($argument) || '' === $argument) {
                    $validatedCommand = [];
                    break;
                }

                $validatedCommand[] = $argument;
            }

            if ([] !== $validatedCommand) {
                $this->phpCommand = $validatedCommand;
            }
        }

        $consolePath = $initializationOptions['consolePath'] ?? null;
        if (\is_string($consolePath) && '' !== $consolePath) {
            $this->consolePath = $consolePath;
        }

        $environment = $initializationOptions['environment'] ?? null;
        if (\is_string($environment) && '' !== $environment) {
            $this->environment = $environment;
        }

        $debug = $initializationOptions['debug'] ?? null;
        if (\is_bool($debug)) {
            $this->debug = $debug;
        }

        $runtimeIndexing = $initializationOptions['runtimeIndexing'] ?? null;
        if (\is_bool($runtimeIndexing)) {
            $this->runtimeIndexing = $runtimeIndexing;
        }

        $projectRoots = $initializationOptions['projectRoots'] ?? null;
        if (\is_array($projectRoots)) {
            $this->projectRoots = array_values(array_filter($projectRoots, static fn (mixed $root): bool => \is_string($root) && '' !== $root));
        }
    }

    /** @param array<array-key, mixed> $settings */
    public function configureProject(Project $project, array $settings): void
    {
        $this->projectSettings[$project->rootPath()] = $settings;
    }

    public function setEnvironment(Project $project, string $environment): void
    {
        $this->projectSettings[$project->rootPath()]['environment'] = $environment;
    }

    /** @return non-empty-list<string> */
    public function phpCommand(?Project $project = null): array
    {
        $command = $this->setting($project, 'phpCommand', $this->phpCommand);
        if (!\is_array($command)) {
            return $this->phpCommand;
        }

        $validated = [];
        foreach ($command as $argument) {
            if (!\is_string($argument) || '' === $argument) {
                return $this->phpCommand;
            }
            $validated[] = $argument;
        }

        return [] === $validated ? $this->phpCommand : $validated;
    }

    public function consolePath(?Project $project = null): string
    {
        $path = $this->setting($project, 'consolePath', $this->consolePath);

        return \is_string($path) && '' !== $path ? $path : $this->consolePath;
    }

    public function environment(?Project $project = null): string
    {
        $environment = $this->setting($project, 'environment', $this->environment);

        return \is_string($environment) && '' !== $environment ? $environment : $this->environment;
    }

    public function debug(?Project $project = null): bool
    {
        $debug = $this->setting($project, 'debug', $this->debug);

        return \is_bool($debug) ? $debug : $this->debug;
    }

    public function runtimeIndexing(?Project $project = null): bool
    {
        $runtimeIndexing = $this->setting($project, 'runtimeIndexing', $this->runtimeIndexing);

        return $this->debug($project) && (\is_bool($runtimeIndexing) ? $runtimeIndexing : $this->runtimeIndexing);
    }

    /** @return list<string> */
    public function projectRoots(): array
    {
        return $this->projectRoots;
    }

    private function setting(?Project $project, string $name, mixed $default): mixed
    {
        return null === $project ? $default : ($this->projectSettings[$project->rootPath()][$name] ?? $default);
    }
}
