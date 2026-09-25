<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Project\Project;

final class CheckProject
{
    public readonly string $rootPath;

    public function __construct(
        public readonly Project $project,
        public readonly string $id,
    ) {
        $this->rootPath = $project->rootPath;
    }
}
