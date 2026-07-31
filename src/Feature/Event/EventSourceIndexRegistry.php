<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Project\Project;

final class EventSourceIndexRegistry
{
    /** @var array<string, EventSourceIndex> */
    private array $indexes = [];

    public function forProject(Project $project): EventSourceIndex
    {
        return $this->indexes[$project->rootPath()] ??= new EventSourceIndex();
    }
}
