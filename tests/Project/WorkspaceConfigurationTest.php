<?php

namespace Symfony\Lsp\Tests\Project;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Translation\TranslationConfigurationRegistry;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\GitignoreMatcher;
use Symfony\Lsp\Project\GlobPatternCompiler;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectConfiguration;
use Symfony\Lsp\Project\ProjectDiscovery;
use Symfony\Lsp\Project\ProjectFileScopeRegistry;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\ProjectSettings;
use Symfony\Lsp\Project\ProjectStateCleaner;
use Symfony\Lsp\Project\ProjectStateInterface;
use Symfony\Lsp\Project\ProjectWorkspace;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Project\WorkspaceConfiguration;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Project\WorkspaceTrustManager;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;
use Symfony\Lsp\Tests\Support\RecordingClient;

final class WorkspaceConfigurationTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory);
        file_put_contents($this->temporaryDirectory.'/composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        @unlink($this->temporaryDirectory.'/.symfony-lsp.json');
        @unlink($this->temporaryDirectory.'/composer.json');
        @rmdir($this->temporaryDirectory);
    }

    public function testUsesRootUriWhenWorkspaceFoldersAreAbsent(): void
    {
        $registry = new ProjectRegistry();
        $runtimeConfiguration = new RuntimeConfiguration();
        $configuration = $this->workspaceConfiguration($registry, $runtimeConfiguration);

        $configuration->initialize([
            'rootUri' => 'file://'.$this->temporaryDirectory,
            'capabilities' => ['general' => ['positionEncodings' => ['utf-8', 'utf-16']]],
            'initializationOptions' => [
                'phpCommand' => ['symfony', 'php'],
                'environment' => 'test',
                'debug' => false,
                'bridgeTimeout' => 90,
            ],
        ]);

        self::assertCount(1, $registry->all());
        self::assertSame(['symfony', 'php'], $runtimeConfiguration->phpCommand());
        self::assertSame('test', $runtimeConfiguration->environment());
        self::assertFalse($runtimeConfiguration->debug());
        self::assertFalse($runtimeConfiguration->runtimeIndexing());
        self::assertSame(90.0, $runtimeConfiguration->bridgeTimeout());
        self::assertSame('utf-8', $configuration->positionEncoding());
    }

    public function testLoadsCheckedInAnalysisSettingsBeforeTrustResolution(): void
    {
        file_put_contents($this->temporaryDirectory.'/.symfony-lsp.json', json_encode([
            'version' => 1,
            'environment' => 'test',
            'runtimeIndexing' => false,
        ], \JSON_THROW_ON_ERROR));
        $registry = new ProjectRegistry();
        $runtimeConfiguration = new RuntimeConfiguration();
        $configuration = $this->workspaceConfiguration($registry, $runtimeConfiguration);

        $configuration->initialize([
            'rootUri' => 'file://'.$this->temporaryDirectory,
            'initializationOptions' => ['workspaceTrust' => true],
        ]);

        self::assertCount(1, $registry->all());
        self::assertSame('test', $runtimeConfiguration->environment($registry->all()[0]));
        self::assertFalse($runtimeConfiguration->runtimeIndexing($registry->all()[0]));
    }

    public function testRejectsEveryInvalidInitializationProjectRoot(): void
    {
        $configuration = $this->workspaceConfiguration(new ProjectRegistry(), new RuntimeConfiguration());

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The project root "missing" was not discovered as a Symfony project.');

        $configuration->initialize([
            'rootUri' => 'file://'.$this->temporaryDirectory,
            'initializationOptions' => ['projectRoots' => ['.', 'missing']],
        ]);
    }

    public function testRejectsInitializationProjectRootsOutsideEveryWorkspaceFolder(): void
    {
        $configuration = $this->workspaceConfiguration(new ProjectRegistry(), new RuntimeConfiguration());
        $outside = \dirname($this->temporaryDirectory);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(\sprintf('The project root "%s" is outside the workspace.', $outside));

        $configuration->initialize([
            'rootUri' => 'file://'.$this->temporaryDirectory,
            'initializationOptions' => ['projectRoots' => [$outside]],
        ]);
    }

    public function testRediscoversProjectsAfterWorkspaceFolderChanges(): void
    {
        $registry = new ProjectRegistry();
        $state = new RecordingProjectState();
        $configuration = $this->workspaceConfiguration($registry, new RuntimeConfiguration(), $state);
        $rootUri = 'file://'.$this->temporaryDirectory;
        $configuration->initialize(['workspaceFolders' => [['uri' => $rootUri]]]);
        mkdir($this->temporaryDirectory.'/nested');
        file_put_contents($this->temporaryDirectory.'/nested/composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/framework-bundle' => '^8.1'],
        ], \JSON_THROW_ON_ERROR));

        $configuration->changeWorkspaceFolders([
            'removed' => [['uri' => $rootUri]],
            'added' => [['uri' => $rootUri.'/nested']],
        ]);

        self::assertCount(1, $registry->all());
        self::assertSame($this->temporaryDirectory.'/nested', $registry->all()[0]->rootPath);
        self::assertSame([$this->temporaryDirectory], $state->removed);
        unlink($this->temporaryDirectory.'/nested/composer.json');
        rmdir($this->temporaryDirectory.'/nested');
    }

    public function testRediscoveringTheSameRootDoesNotReleaseProjectState(): void
    {
        $registry = new ProjectRegistry();
        $state = new RecordingProjectState();
        $configuration = $this->workspaceConfiguration($registry, new RuntimeConfiguration(), $state);
        $rootUri = 'file://'.$this->temporaryDirectory;
        $configuration->initialize(['workspaceFolders' => [['uri' => $rootUri]]]);

        $configuration->rediscoverProjects();

        self::assertCount(1, $registry->all());
        self::assertSame([], $state->removed);
    }

    private function workspaceConfiguration(ProjectRegistry $registry, RuntimeConfiguration $runtimeConfiguration, ?ProjectStateInterface $state = null): WorkspaceConfiguration
    {
        $uriToPathConverter = new UriToPathConverter();
        $analysisSettings = new AnalysisSettings();
        $projectConfiguration = new ProjectConfiguration($uriToPathConverter, $analysisSettings);
        $projectSettings = new ProjectSettings($this->client(), $registry, new TranslationConfigurationRegistry(), $runtimeConfiguration, $projectConfiguration, new ProjectFileScopeRegistry(new GlobPatternCompiler()), $analysisSettings);

        return new WorkspaceConfiguration(
            new ProjectWorkspace(
                $projectConfiguration,
                new ProjectDiscovery($uriToPathConverter, new GitignoreMatcher()),
                $registry,
                $projectSettings,
                new ProjectStateCleaner(null === $state ? [] : [$state]),
                $runtimeConfiguration,
                $uriToPathConverter,
            ),
            $registry,
            new WorkspaceTrustManager($this->client(), new WorkspaceTrust(), $this->runtimeInitializer(), new ProjectIndexStatusRegistry(), $runtimeConfiguration, $registry),
            $runtimeConfiguration,
            $projectSettings,
            new PositionConverter(),
        );
    }

    private function client(): ClientInterface
    {
        return new RecordingClient();
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

final class RecordingProjectState implements ProjectStateInterface
{
    /** @var list<string> */
    public array $removed = [];

    public function removeProject(Project $project): void
    {
        $this->removed[] = $project->rootPath;
    }
}
