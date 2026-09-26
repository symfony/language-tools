<?php

namespace Symfony\Lsp\Project;

/**
 * Keeps the analysis settings of the workspace and of every project.
 *
 * The workspace settings come from the initialization options or the command
 * line; a project's settings are what `ProjectSettings` resolved for it from
 * the checked-in configuration, those workspace settings and the editor. Each
 * option a project leaves unset falls back to the workspace. An environment or
 * kernel switched by command wins until the configured value of that option
 * changes.
 */
final class AnalysisSettingsRegistry implements ProjectStateInterface
{
    private ProjectAnalysisSettings $workspace;

    /** @var array<string, ProjectAnalysisSettings> */
    private array $projects = [];

    /** @var array<string, string> */
    private array $environmentOverrides = [];

    /** @var array<string, ?string> */
    private array $kernelOverrides = [];

    public function __construct()
    {
        $this->workspace = new ProjectAnalysisSettings();
    }

    public function configureWorkspace(ProjectAnalysisSettings $settings): void
    {
        $this->workspace = $this->workspace->merge($settings);
    }

    public function configureProject(Project $project, ProjectAnalysisSettings $settings): void
    {
        $previous = $this->projects[$project->rootPath] ?? null;
        if (null !== $previous && $previous->environment !== $settings->environment) {
            unset($this->environmentOverrides[$project->rootPath]);
        }
        if (null !== $previous && $previous->kernel !== $settings->kernel) {
            unset($this->kernelOverrides[$project->rootPath]);
        }
        $this->projects[$project->rootPath] = $settings;
    }

    public function workspace(): ProjectAnalysisSettings
    {
        return $this->workspace;
    }

    public function forProject(?Project $project): ProjectAnalysisSettings
    {
        if (null === $project) {
            return $this->workspace;
        }
        $root = $project->rootPath;
        $settings = $this->projects[$root] ?? new ProjectAnalysisSettings();
        if (\array_key_exists($root, $this->kernelOverrides)) {
            $kernel = $this->kernelOverrides[$root];
            $settings = null === $kernel ? $settings->withoutKernel() : $settings->merge(new ProjectAnalysisSettings(kernel: $kernel));
        }
        if (isset($this->environmentOverrides[$root])) {
            $settings = $settings->merge(new ProjectAnalysisSettings(environment: $this->environmentOverrides[$root]));
        }

        return $this->workspace->merge($settings);
    }

    public function setEnvironment(Project $project, string $environment): void
    {
        $this->environmentOverrides[$project->rootPath] = $environment;
    }

    public function setKernel(Project $project, ?string $kernel): void
    {
        $this->kernelOverrides[$project->rootPath] = $kernel;
    }

    public function removeProject(Project $project): void
    {
        unset($this->projects[$project->rootPath], $this->environmentOverrides[$project->rootPath], $this->kernelOverrides[$project->rootPath]);
    }
}
