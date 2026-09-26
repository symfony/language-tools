<?php

namespace Symfony\Lsp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Server\WorkspaceFileWatcher;
use Symfony\Lsp\Tests\Support\ProjectPaths;
use Symfony\Lsp\Tests\Support\RecordingClient;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class WorkspaceFileWatcherTest extends TestCase
{
    private TestWorkspace $workspace;
    private ProjectRegistry $projects;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-watch-');
        foreach (['src', 'custom-package', 'var', 'vendor'] as $directory) {
            $this->workspace->mkdir($directory);
        }
        $this->workspace->write('.gitignore', "/var/\n");
        $this->projects = new ProjectRegistry();
        $this->projects->replace([new Project($this->workspace->rootPath, 'file:///workspace')]);
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testRegistersApplicationDirectoriesForRelativePatternClients(): void
    {
        $client = new RecordingClient();
        $watcher = new WorkspaceFileWatcher($client, $this->projects, new UriToPathConverter(), ProjectPaths::policy());
        $watcher->initialize(['capabilities' => ['workspace' => ['didChangeWatchedFiles' => [
            'dynamicRegistration' => true,
            'relativePatternSupport' => true,
        ]]]]);

        $watcher->register();
        $watcher->register();

        $sourcePattern = '*.{php,twig,yaml,yml,ini,json,xml,xlf,xliff,css,js,mjs,ts,svg,png,jpg,jpeg,gif,webp,woff,woff2,ttf,otf,wasm}';
        self::assertSame([[
            'method' => 'client/registerCapability',
            'params' => ['registrations' => [[
                'id' => 'symfony-lsp-workspace-files',
                'method' => 'workspace/didChangeWatchedFiles',
                'registerOptions' => ['watchers' => [
                    ['globPattern' => '**/composer.{json,lock}'],
                    ['globPattern' => '**/.symfony-lsp.json'],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => $sourcePattern]],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => '.env*']],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => '.gitignore']],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => 'composer.{json,lock}']],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => '*'], 'kind' => 5],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => 'custom-package/**/'.$sourcePattern]],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => 'custom-package/**/.gitignore']],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => 'custom-package'], 'kind' => 5],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => 'custom-package/**'], 'kind' => 5],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => 'src/**/'.$sourcePattern]],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => 'src/**/.gitignore']],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => 'src'], 'kind' => 5],
                    ['globPattern' => ['baseUri' => 'file:///workspace', 'pattern' => 'src/**'], 'kind' => 5],
                ]],
            ]]],
        ]], $client->requests);
    }

    public function testDetectsNewTopLevelSourceDirectories(): void
    {
        $watcher = new WorkspaceFileWatcher(new RecordingClient(), $this->projects, new UriToPathConverter(), ProjectPaths::policy());
        $this->workspace->mkdir('module');

        self::assertTrue($watcher->requiresRefreshForChange('file://'.$this->workspace->path('module'), 1));
        self::assertFalse($watcher->requiresRefreshForChange('file://'.$this->workspace->path('vendor'), 1));
        self::assertFalse($watcher->requiresRefreshForChange('file://'.$this->workspace->path('module'), 2));
    }

    public function testFallsBackToWorkspaceGlobsWithoutRelativePatternSupport(): void
    {
        $client = new RecordingClient();
        $watcher = new WorkspaceFileWatcher($client, $this->projects, new UriToPathConverter(), ProjectPaths::policy());
        $watcher->initialize(['capabilities' => ['workspace' => ['didChangeWatchedFiles' => ['dynamicRegistration' => true]]]]);

        $watcher->register();

        self::assertSame([[
            'method' => 'client/registerCapability',
            'params' => ['registrations' => [[
                'id' => 'symfony-lsp-workspace-files',
                'method' => 'workspace/didChangeWatchedFiles',
                'registerOptions' => ['watchers' => [
                    ['globPattern' => '**/*.{php,twig,yaml,yml,ini,json,xml,xlf,xliff,css,js,mjs,ts,svg,png,jpg,jpeg,gif,webp,woff,woff2,ttf,otf,wasm}'],
                    ['globPattern' => '**/.env*'],
                    ['globPattern' => '**/.gitignore'],
                    ['globPattern' => '**/composer.{json,lock}'],
                    ['globPattern' => '**/.symfony-lsp.json'],
                ]],
            ]]],
        ]], $client->requests);
    }

    public function testRefreshesRegistrationAfterProjectDiscoveryChanges(): void
    {
        $client = new RecordingClient();
        $watcher = new WorkspaceFileWatcher($client, $this->projects, new UriToPathConverter(), ProjectPaths::policy());
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
        $client = new RecordingClient();
        $watcher = new WorkspaceFileWatcher($client, $this->projects, new UriToPathConverter(), ProjectPaths::policy());
        $watcher->initialize(['capabilities' => ['workspace' => ['didChangeWatchedFiles' => ['dynamicRegistration' => false]]]]);

        $watcher->register();

        self::assertSame([], $client->requests);
    }
}
