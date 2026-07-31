<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Project\Project;

final class SecurityIndexRegistry
{
    /** @var array<string, SecurityIndex> */
    private array $indexes = [];

    public function forProject(Project $project): SecurityIndex
    {
        return $this->indexes[$project->rootPath()] ??= new SecurityIndex();
    }
}
