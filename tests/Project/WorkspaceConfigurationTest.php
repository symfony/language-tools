<?php

namespace Symfony\Lsp\Tests\Project;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Translation\TranslationConfigurationRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectDiscovery;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\ProjectSettings;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Project\WorkspaceConfiguration;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Project\WorkspaceTrustManager;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;

final class WorkspaceConfigurationTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory);
        file_put_contents($this->temporaryDirectory.'/composer.json', json_encode([
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        @unlink($this->temporaryDirectory.'/composer.json');
        @rmdir($this->temporaryDirectory);
    }

    public function testUsesRootUriWhenWorkspaceFoldersAreAbsent(): void
    {
        $registry = new ProjectRegistry();
        $runtimeConfiguration = new RuntimeConfiguration();
        $configuration = new WorkspaceConfiguration(
            new ProjectDiscovery(new UriToPathConverter()),
            $registry,
            new WorkspaceTrustManager($this->client(), new WorkspaceTrust(), $this->runtimeInitializer()),
            $runtimeConfiguration,
            new ProjectSettings($this->client(), $registry, new TranslationConfigurationRegistry(), $runtimeConfiguration),
            new PositionConverter(),
        );

        $configuration->initialize([
            'rootUri' => 'file://'.$this->temporaryDirectory,
            'capabilities' => ['general' => ['positionEncodings' => ['utf-8', 'utf-16']]],
            'initializationOptions' => [
                'phpCommand' => ['symfony', 'php'],
                'consolePath' => 'app-console',
                'environment' => 'test',
                'debug' => false,
            ],
        ]);

        self::assertCount(1, $registry->all());
        self::assertSame('^8.0', $registry->all()[0]->frameworkBundleConstraint());
        self::assertSame(['symfony', 'php'], $runtimeConfiguration->phpCommand());
        self::assertSame('app-console', $runtimeConfiguration->consolePath());
        self::assertSame('test', $runtimeConfiguration->environment());
        self::assertFalse($runtimeConfiguration->debug());
        self::assertSame('utf-8', $configuration->positionEncoding());
    }

    public function testRediscoversProjectsAfterWorkspaceFolderChanges(): void
    {
        $registry = new ProjectRegistry();
        $runtimeConfiguration = new RuntimeConfiguration();
        $configuration = new WorkspaceConfiguration(
            new ProjectDiscovery(new UriToPathConverter()),
            $registry,
            new WorkspaceTrustManager($this->client(), new WorkspaceTrust(), $this->runtimeInitializer()),
            $runtimeConfiguration,
            new ProjectSettings($this->client(), $registry, new TranslationConfigurationRegistry(), $runtimeConfiguration),
            new PositionConverter(),
        );
        $rootUri = 'file://'.$this->temporaryDirectory;
        $configuration->initialize(['workspaceFolders' => [['uri' => $rootUri]]]);
        mkdir($this->temporaryDirectory.'/nested');
        file_put_contents($this->temporaryDirectory.'/nested/composer.json', json_encode([
            'require' => ['symfony/framework-bundle' => '^8.1'],
        ], \JSON_THROW_ON_ERROR));

        $configuration->changeWorkspaceFolders(['event' => [
            'removed' => [['uri' => $rootUri]],
            'added' => [['uri' => $rootUri.'/nested']],
        ]]);

        self::assertCount(1, $registry->all());
        self::assertSame($this->temporaryDirectory.'/nested', $registry->all()[0]->rootPath());
        unlink($this->temporaryDirectory.'/nested/composer.json');
        rmdir($this->temporaryDirectory.'/nested');
    }

    private function client(): ClientInterface
    {
        return new class implements ClientInterface {
            public function request(string $method, array $params): mixed
            {
                return null;
            }

            public function notify(string $method, array $params): void
            {
            }
        };
    }

    private function runtimeInitializer(): RuntimeInitializerInterface
    {
        return new class implements RuntimeInitializerInterface {
            public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
            {
            }
        };
    }
}
