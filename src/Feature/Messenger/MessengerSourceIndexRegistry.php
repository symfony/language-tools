<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Project\Project;

final class MessengerSourceIndexRegistry
{
    /** @var array<string, MessengerSourceIndex> */
    private array $indexes = [];

    public function forProject(Project $project): MessengerSourceIndex
    {
        return $this->indexes[$project->rootPath()] ??= new MessengerSourceIndex();
    }
}
