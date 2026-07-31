<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Project\Project;

final class ConfigurationIndexRegistry
{
    /** @var array<string, ConfigurationIndex> */
    private array $indexes = [];

    public function forProject(Project $project): ConfigurationIndex
    {
        return $this->indexes[$project->rootPath()] ??= new ConfigurationIndex();
    }
}
