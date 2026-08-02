<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Project\Project;

final class TwigComponentIndexRegistry
{
    /** @var array<string, TwigComponentIndex> */
    private array $indexes = [];

    public function forProject(Project $project): TwigComponentIndex
    {
        return $this->indexes[$project->rootPath()] ??= new TwigComponentIndex();
    }
}
