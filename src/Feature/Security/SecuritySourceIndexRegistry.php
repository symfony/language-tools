<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Project\Project;

final class SecuritySourceIndexRegistry
{
    /** @var array<string, SecuritySourceIndex> */
    private array $indexes = [];

    public function forProject(Project $project): SecuritySourceIndex
    {
        return $this->indexes[$project->rootPath()] ??= new SecuritySourceIndex();
    }
}
