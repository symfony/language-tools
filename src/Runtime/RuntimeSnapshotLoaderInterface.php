<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\Project;

interface RuntimeSnapshotLoaderInterface
{
    public function section(): string;

    /**
     * @param array<array-key, mixed> $snapshot
     */
    public function load(Project $project, array $snapshot): void;
}
