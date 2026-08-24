<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Project\Project;

final class CheckFile
{
    public function __construct(
        public readonly Project $project,
        public readonly string $path,
        public readonly string $projectPath,
        public readonly string $workspacePath,
        public readonly string $uri,
        public readonly string $languageId,
    ) {
    }
}
