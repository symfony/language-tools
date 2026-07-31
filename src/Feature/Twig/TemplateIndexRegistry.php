<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Project\Project;

final class TemplateIndexRegistry
{
    /** @var array<string, TemplateIndex> */
    private array $indexes = [];

    public function forProject(Project $project): TemplateIndex
    {
        return $this->indexes[$project->rootPath()] ??= new TemplateIndex();
    }
}
