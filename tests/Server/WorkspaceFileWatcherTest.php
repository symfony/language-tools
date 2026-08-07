<?php

namespace Symfony\Lsp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Server\WorkspaceFileWatcher;

final class WorkspaceFileWatcherTest extends TestCase
{
    public function testRegistersSymfonyWorkspaceFilesForSupportedClients(): void
    {
        $client = new WorkspaceFileWatcherClient();
        $watcher = new WorkspaceFileWatcher($client);
        $watcher->initialize(['capabilities' => ['workspace' => ['didChangeWatchedFiles' => ['dynamicRegistration' => true]]]]);

        $watcher->register();
        $watcher->register();

        self::assertSame([[
            'method' => 'client/registerCapability',
            'params' => [
                'registrations' => [[
                    'id' => 'symfony-lsp-workspace-files',
                    'method' => 'workspace/didChangeWatchedFiles',
                    'registerOptions' => [
                        'watchers' => [
                            ['globPattern' => '**/*.{php,twig,yaml,yml,json,xml,xlf,xliff,css,js,mjs,ts,svg,png,jpg,jpeg,gif,webp,woff,woff2,ttf,otf,wasm}'],
                            ['globPattern' => '**/.env*'],
                            ['globPattern' => '**/composer.{json,lock}'],
                        ],
                    ],
                ]],
            ],
        ]], $client->requests);
    }

    public function testDoesNotRegisterForUnsupportedClients(): void
    {
        $client = new WorkspaceFileWatcherClient();
        $watcher = new WorkspaceFileWatcher($client);
        $watcher->initialize(['capabilities' => ['workspace' => ['didChangeWatchedFiles' => ['dynamicRegistration' => false]]]]);

        $watcher->register();

        self::assertSame([], $client->requests);
    }
}

final class WorkspaceFileWatcherClient implements ClientInterface
{
    /** @var list<array{method: string, params: array<array-key, mixed>}> */
    public array $requests = [];

    public function request(string $method, array $params): mixed
    {
        $this->requests[] = ['method' => $method, 'params' => $params];

        return null;
    }

    public function notify(string $method, array $params): void
    {
    }
}
