<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\Project;

interface RuntimeRefreshObserverInterface
{
    public function refreshed(Project $project): void;
}
