<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\SnapshotSection;

final class ProjectEventSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly EventIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'events';
    }

    public function load(Project $project, SnapshotSection $section): void
    {
        $events = [];
        foreach ($section->items('events', 'name') as $item) {
            $events[] = new Event($item->string('name'), $item->optionalString('class'));
        }
        $listeners = [];
        foreach ($section->items('listeners', 'event', 'class', 'method') as $item) {
            $listeners[] = new EventListener(
                $item->string('event'),
                $item->string('class'),
                $item->string('method'),
                $item->int('priority'),
            );
        }
        $this->indexes->forProject($project)->replace($events, $listeners, $section->complete());
    }
}
