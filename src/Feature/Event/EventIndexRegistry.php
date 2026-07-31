<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Project\Project;

final class EventIndexRegistry
{
    /** @var array<string, EventIndex> */
    private array $indexes = [];

    public function forProject(Project $project): EventIndex
    {
        return $this->indexes[$project->rootPath()] ??= new EventIndex();
    }
}
