<?php

namespace Symfony\Lsp\Tests\Feature\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Messenger\MessengerIndexRegistry;
use Symfony\Lsp\Feature\Messenger\ProjectMessengerSnapshotLoader;
use Symfony\Lsp\Project\Project;

final class ProjectMessengerSnapshotLoaderTest extends TestCase
{
    public function testLoadsRuntimeGraph(): void
    {
        $indexes = new MessengerIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        (new ProjectMessengerSnapshotLoader($indexes))->load($project, ['sections' => ['messenger' => [
            'complete' => true,
            'buses' => [['name' => 'command.bus', 'default' => true]],
            'transports' => [['name' => 'async', 'failure' => false]],
            'messages' => [['class' => 'App\\Message\\Ping', 'transports' => ['async']]],
            'handlers' => [[
                'message' => 'App\\Message\\Ping',
                'bus' => 'command.bus',
                'service' => 'handler',
                'class' => 'App\\MessageHandler\\PingHandler',
                'method' => '__invoke',
                'priority' => 10,
                'fromTransport' => 'async',
            ]],
        ]]]);

        $index = $indexes->forProject($project);
        self::assertTrue($index->isComplete());
        self::assertTrue($index->bus('command.bus')?->isDefault());
        self::assertSame(['async'], $index->message('App\\Message\\Ping')?->transports());
        self::assertSame('App\\MessageHandler\\PingHandler', $index->handlersForMessage('App\\Message\\Ping')[0]->className());
        self::assertSame(10, $index->handlersForMessage('App\\Message\\Ping')[0]->priority());
    }
}
