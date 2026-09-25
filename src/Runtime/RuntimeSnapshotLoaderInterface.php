<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\Project;

interface RuntimeSnapshotLoaderInterface
{
    public function section(): string;

    public function load(Project $project, SnapshotSection $section): void;
}
