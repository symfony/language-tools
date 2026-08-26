<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

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

    public function load(Project $project, array $snapshot): void
    {
        $sections = $snapshot['sections'] ?? null;
        $section = \is_array($sections) ? ($sections['console'] ?? null) : null;
        if (!\is_array($section)) {
            return;
        }
        $commands = [];
        foreach (\is_array($section['commands'] ?? null) ? $section['commands'] : [] as $item) {
            if (!\is_array($item) || !\is_string($item['class'] ?? null)) {
                continue;
            }
            $file = \is_string($item['file'] ?? null) ? $this->pathMapper->toHost($project, $item['file']) : null;
            $commands[] = new ConsoleCommandMetadata(
                $item['class'],
                $file,
                $this->strings($item['arguments'] ?? []),
                $this->strings($item['options'] ?? []),
                true === ($item['complete'] ?? false),
            );
        }
        $this->indexes->forProject($project)->replace($commands, true === ($section['complete'] ?? false));
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }
        $values = array_values(array_unique(array_filter($values, 'is_string')));
        sort($values);

        return $values;
    }
}
