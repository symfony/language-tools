<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ProjectEventSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly EventIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'events';
    }

    public function load(Project $project, array $snapshot): void
    {
        $sections = $snapshot['sections'] ?? null;
        $section = \is_array($sections) ? ($sections['events'] ?? null) : null;
        if (!\is_array($section)) {
            return;
        }
        $events = [];
        foreach (\is_array($section['events'] ?? null) ? $section['events'] : [] as $item) {
            if (\is_array($item) && \is_string($item['name'] ?? null)) {
                $events[] = new Event($item['name'], \is_string($item['class'] ?? null) ? $item['class'] : null);
            }
        }
        $listeners = [];
        foreach (\is_array($section['listeners'] ?? null) ? $section['listeners'] : [] as $item) {
            if (!\is_array($item) || !\is_string($item['event'] ?? null) || !\is_string($item['class'] ?? null) || !\is_string($item['method'] ?? null)) {
                continue;
            }
            $listeners[] = new EventListener($item['event'], $item['class'], $item['method'], \is_int($item['priority'] ?? null) ? $item['priority'] : 0);
        }
        $this->indexes->forProject($project)->replace($events, $listeners, true === ($section['complete'] ?? false));
    }
}
