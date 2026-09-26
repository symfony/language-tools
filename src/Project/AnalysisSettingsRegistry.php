<?php

namespace Symfony\Lsp\Project;

/**
 * Keeps the analysis settings of the workspace and of every project.
 *
 * The workspace settings come from the initialization options or the command
 * line; a project's settings are what `ProjectSettings` resolved for it from
 * the checked-in configuration, those workspace settings and the editor. Each
 * option a project leaves unset falls back to the workspace.
 */
final class AnalysisSettingsRegistry implements ProjectStateInterface
{
    private ProjectAnalysisSettings $workspace;

    /** @var array<string, ProjectAnalysisSettings> */
    private array $projects = [];

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
        $this->projects[$project->rootPath] = $settings;
    }

    public function workspace(): ProjectAnalysisSettings
    {
        return $this->workspace;
    }

    public function forProject(?Project $project): ProjectAnalysisSettings
    {
        $settings = null === $project ? null : ($this->projects[$project->rootPath] ?? null);

        return null === $settings ? $this->workspace : $this->workspace->merge($settings);
    }

    public function setEnvironment(Project $project, string $environment): void
    {
        $this->configureProject($project, $this->forProject($project)->merge(new ProjectAnalysisSettings(environment: $environment)));
    }

    public function setKernel(Project $project, ?string $kernel): void
    {
        $settings = $this->forProject($project);
        $this->configureProject($project, null === $kernel
            ? $settings->withoutKernel()
            : $settings->merge(new ProjectAnalysisSettings(kernel: $kernel)));
    }

    public function removeProject(Project $project): void
    {
        unset($this->projects[$project->rootPath]);
    }
}
