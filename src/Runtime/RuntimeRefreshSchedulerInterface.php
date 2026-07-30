<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\Project;

interface RuntimeRefreshSchedulerInterface
{
    public function schedule(Project $project): void;
}
