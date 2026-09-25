<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\RuntimeSnapshotValues;

final class ProjectConsoleSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(
        private readonly ConsoleIndexRegistry $indexes,
        private readonly ContainerPathMapper $pathMapper,
    ) {
    }

    public function section(): string
    {
        return 'console';
    }

    public function load(Project $project, array $section): void
    {
        $commands = [];
        foreach (\is_array($section['commands'] ?? null) ? $section['commands'] : [] as $item) {
            if (!\is_array($item) || !\is_string($item['class'] ?? null)) {
                continue;
            }
            $file = \is_string($item['file'] ?? null) ? $this->pathMapper->toHost($project, $item['file']) : null;
            $commands[] = new ConsoleCommandMetadata(
                $item['class'],
                $file,
                RuntimeSnapshotValues::stringList($item['arguments'] ?? null),
                RuntimeSnapshotValues::stringList($item['options'] ?? null),
                true === ($item['complete'] ?? false),
            );
        }
        $this->indexes->forProject($project)->replace($commands, true === ($section['complete'] ?? false));
    }
}
