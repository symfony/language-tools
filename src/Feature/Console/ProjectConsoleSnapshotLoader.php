<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\SnapshotSection;

final class ProjectConsoleSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly ConsoleIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'console';
    }

    public function load(Project $project, SnapshotSection $section): void
    {
        $commands = [];
        foreach ($section->items('commands', 'class') as $item) {
            $commands[] = new ConsoleCommandMetadata(
                $item->string('class'),
                $item->optionalPath('file'),
                $item->strings('arguments'),
                $item->strings('options'),
                $item->complete(),
            );
        }
        $this->indexes->forProject($project)->replace($commands, $section->complete());
    }
}
