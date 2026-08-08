<?php

namespace Symfony\Lsp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Server\WorkspaceFileWatcher;

final class WorkspaceFileWatcherTest extends TestCase
{
    private string $directory;
    private ProjectRegistry $projects;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/symfony-lsp-watch-'.bin2hex(random_bytes(6));
        foreach (['src', 'custom-package', 'var', 'vendor'] as $directory) {
            mkdir($this->directory.'/'.$directory, 0777, true);
        }
        $this->projects = new ProjectRegistry();
        $this->projects->replace([new Project($this->directory, 'file:///workspace', '^8.0')]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testRegistersApplicationDirectoriesForRelativePatternClients(): void
    {
        $client = new WorkspaceFileWatcherClient();
        $watcher = new WorkspaceFileWatcher($client, $this->projects);
        $watcher->initialize(['capabilities' => ['workspace' => ['didChangeWatchedFiles' => [
            'dynamicRegistration' => true,
            'relativePatternSupport' => true,
        ]]]]);

        $watcher->register();
        $watcher->register();

        $sourcePattern = '*.{php,twig,yaml,yml,json,xml,xlf,xliff,css,js,mjs,ts,svg,png,jpg,jpeg,gif,webp,woff,woff2,ttf,otf,wasm}';
        self::assertSame([[
            'method' => 'client/registerCapability',
            'params' => ['registrations' => [[
                'id' => 'symfony-lsp-workspace-files',
                'method' => 'workspace/didChangeWatchedFiles',
                'registerOptions' => ['watchers' => [
                    ['globPattern' => '**/composer.{json,lock}'],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => $sourcePattern]],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => '.env*']],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => 'composer.{json,lock}']],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => 'custom-package/**/'.$sourcePattern]],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => 'custom-package'], 'kind' => 5],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => 'custom-package/**'], 'kind' => 5],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => 'src/**/'.$sourcePattern]],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => 'src'], 'kind' => 5],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => 'src/**'], 'kind' => 5],
                ]],
            ]]],
        ]], $client->requests);
    }

    public function testFallsBackToWorkspaceGlobsWithoutRelativePatternSupport(): void
    {
        $client = new WorkspaceFileWatcherClient();
        $watcher = new WorkspaceFileWatcher($client, $this->projects);
        $watcher->initialize(['capabilities' => ['workspace' => ['didChangeWatchedFiles' => ['dynamicRegistration' => true]]]]);

        $watcher->register();

        self::assertSame([[
            'method' => 'client/registerCapability',
            'params' => ['registrations' => [[
                'id' => 'symfony-lsp-workspace-files',
                'method' => 'workspace/didChangeWatchedFiles',
                'registerOptions' => ['watchers' => [
                    ['globPattern' => '**/*.{php,twig,yaml,yml,json,xml,xlf,xliff,css,js,mjs,ts,svg,png,jpg,jpeg,gif,webp,woff,woff2,ttf,otf,wasm}'],
                    ['globPattern' => '**/.env*'],
                    ['globPattern' => '**/composer.{json,lock}'],
                ]],
            ]]],
        ]], $client->requests);
    }

    public function testRefreshesRegistrationAfterProjectDiscoveryChanges(): void
    {
        $client = new WorkspaceFileWatcherClient();
        $watcher = new WorkspaceFileWatcher($client, $this->projects);
        $watcher->initialize(['capabilities' => ['workspace' => ['didChangeWatchedFiles' => [
            'dynamicRegistration' => true,
            'relativePatternSupport' => true,
        ]]]]);
        $watcher->register();

        $watcher->refresh();

        self::assertSame([
            'client/registerCapability',
            'client/unregisterCapability',
            'client/registerCapability',
        ], array_column($client->requests, 'method'));
        self::assertSame([
            'unregisterations' => [[
                'id' => 'symfony-lsp-workspace-files',
                'method' => 'workspace/didChangeWatchedFiles',
            ]],
        ], $client->requests[1]['params']);
    }

    public function testDoesNotRegisterForUnsupportedClients(): void
    {
        $client = new WorkspaceFileWatcherClient();
        $watcher = new WorkspaceFileWatcher($client, $this->projects);
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
