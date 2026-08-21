<?php

namespace Symfony\Lsp\Project;

interface ProjectStateInterface
{
    public function removeProject(Project $project): void;
}
