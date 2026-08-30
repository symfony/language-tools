<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectStateInterface;

final class ConfigurationValidationRegistry implements ProjectStateInterface
{
    /** @var array<string, ConfigurationValidationResult> */
    private array $results = [];

    /** @var array<string, int> */
    private array $generations = [];

    public function result(Project $project): ConfigurationValidationResult
    {
        return $this->results[$project->rootPath] ?? new ConfigurationValidationResult(ConfigurationValidationResult::UNAVAILABLE);
    }

    public function replace(Project $project, ConfigurationValidationResult $result): void
    {
        $this->results[$project->rootPath] = $result;
    }

    public function pending(Project $project): void
    {
        $root = $project->rootPath;
        $this->generations[$root] = ($this->generations[$root] ?? 0) + 1;
        $this->replace($project, new ConfigurationValidationResult(ConfigurationValidationResult::PENDING));
    }

    public function generation(Project $project): int
    {
        return $this->generations[$project->rootPath] ?? 0;
    }

    public function removeProject(Project $project): void
    {
        unset($this->results[$project->rootPath], $this->generations[$project->rootPath]);
    }
}
