<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Project\Project;

final class CheckPlan
{
    /**
     * @param list<CheckFile>                $files
     * @param array<string, list<CheckFile>> $filesByProject
     * @param array<string, Project>         $projects
     */
    public function __construct(
        public readonly string $workspace,
        public readonly array $files,
        public readonly array $filesByProject,
        public readonly array $projects,
    ) {
    }
}
