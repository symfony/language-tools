<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Project\Project;

final class MessengerIndexRegistry
{
    /** @var array<string, MessengerIndex> */
    private array $indexes = [];

    public function forProject(Project $project): MessengerIndex
    {
        return $this->indexes[$project->rootPath()] ??= new MessengerIndex();
    }
}
