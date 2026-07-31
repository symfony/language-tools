<?php

namespace Symfony\Lsp\Tests\Feature\Event;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Event\EventIndexRegistry;
use Symfony\Lsp\Feature\Event\ProjectEventSnapshotLoader;
use Symfony\Lsp\Project\Project;

final class ProjectEventSnapshotLoaderTest extends TestCase
{
    public function testLoadsRuntimeGraph(): void
    {
        $indexes = new EventIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        (new ProjectEventSnapshotLoader($indexes))->load($project, ['sections' => ['events' => [
            'complete' => true,
            'events' => [['name' => 'App\\Event\\OrderPlaced', 'class' => 'App\\Event\\OrderPlaced']],
            'listeners' => [[
                'event' => 'App\\Event\\OrderPlaced',
                'class' => 'App\\EventListener\\NotifyCustomer',
                'method' => 'onOrderPlaced',
                'priority' => 10,
            ]],
        ]]]);

        $index = $indexes->forProject($project);
        self::assertTrue($index->isComplete());
        self::assertSame('App\\Event\\OrderPlaced', $index->event('App\\Event\\OrderPlaced')?->className());
        self::assertSame('App\\EventListener\\NotifyCustomer', $index->listenersForEvent('App\\Event\\OrderPlaced')[0]->className());
        self::assertSame(10, $index->listenersForEvent('App\\Event\\OrderPlaced')[0]->priority());
    }
}
