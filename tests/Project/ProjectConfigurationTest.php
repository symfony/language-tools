<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectConfiguration;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class ProjectConfigurationTest extends TestCase
{
    private TestWorkspace $workspace;
    private ProjectConfiguration $configuration;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-config-');
        $this->workspace->mkdir('apps/admin');
        $this->configuration = new ProjectConfiguration(new UriToPathConverter(), new AnalysisSettings());
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testLoadsWorkspaceDefaultsAndProjectOverrides(): void
    {
        $this->workspace->write('.symfony-lsp.json', json_encode([
            'version' => 1,
            'projectRoots' => ['.', 'apps/admin'],
            'environment' => 'prod',
            'bridgeTimeout' => 90,
            'releaseMetadata' => false,
            'excludePaths' => ['tests/**'],
            'projects' => [
                'apps/admin' => [
                    'environment' => 'admin',
                    'translationDiagnostics' => true,
                    'excludePaths' => ['./tests/Fixtures/**', 'var/generated/'],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));
        $this->configuration->load([['uri' => (new UriToPathConverter())->toUri($this->workspace->rootPath)]]);
        $project = new Project($this->workspace->path('apps/admin'), 'file:///workspace/apps/admin');

        self::assertSame(['.', 'apps/admin'], $this->configuration->projectRoots($this->workspace->rootPath));
        $settings = $this->configuration->settings($project);
        self::assertSame('admin', $settings->environment);
        self::assertSame(90.0, $settings->bridgeTimeout);
        self::assertFalse($settings->releaseMetadata);
        self::assertSame(['tests/Fixtures/**', 'var/generated/**'], $settings->excludePaths);
        self::assertTrue($settings->translationDiagnostics);
        self::assertSame('apps/admin', $this->configuration->projectId($project));
        self::assertSame('apps/admin/config/services.yaml', $this->configuration->workspaceRelativePath($project, $project->rootPath.'/config/services.yaml'));
    }

    public function testKeepsTheLastValidConfigurationWhenReloadFails(): void
    {
        $path = $this->workspace->path('.symfony-lsp.json');
        file_put_contents($path, json_encode([
            'version' => 1,
            'environment' => 'test',
        ], \JSON_THROW_ON_ERROR));
        $workspace = [['uri' => (new UriToPathConverter())->toUri($this->workspace->rootPath)]];
        $this->configuration->load($workspace);
        file_put_contents($path, '{');

        try {
            $this->configuration->load($workspace);
            self::fail('The invalid configuration was accepted.');
        } catch (InvalidConfigurationException) {
        }

        self::assertSame(
            'test',
            $this->configuration->settings(new Project($this->workspace->rootPath, 'file:///workspace'))->environment,
        );
    }

    public function testRejectsProjectOverridesThatDoNotMatchDiscoveredProjects(): void
    {
        $this->workspace->write('.symfony-lsp.json', json_encode([
            'version' => 1,
            'projects' => [
                'apps/admin' => ['environment' => 'admin'],
            ],
        ], \JSON_THROW_ON_ERROR));
        $this->configuration->load([['uri' => (new UriToPathConverter())->toUri($this->workspace->rootPath)]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('apps/admin');

        $this->configuration->validateProjects([]);
    }

    public function testRejectsExcludePathsOutsideProjects(): void
    {
        $this->workspace->write('.symfony-lsp.json', json_encode([
            'version' => 1,
            'excludePaths' => ['../outside/**'],
        ], \JSON_THROW_ON_ERROR));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('inside each Symfony project');

        $this->configuration->load([['uri' => (new UriToPathConverter())->toUri($this->workspace->rootPath)]]);
    }

    public function testRejectsUnknownAndInvalidOptions(): void
    {
        $this->workspace->write('.symfony-lsp.json', json_encode([
            'version' => 1,
            'bridgeTimeout' => 0,
        ], \JSON_THROW_ON_ERROR));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('bridgeTimeout');

        $this->configuration->load([['uri' => (new UriToPathConverter())->toUri($this->workspace->rootPath)]]);
    }

    #[DataProvider('escapingKernels')]
    public function testRejectsKernelEntryPointsThatLeaveTheProject(string $kernel, string $message): void
    {
        $this->workspace->write('.symfony-lsp.json', json_encode([
            'version' => 1,
            'kernel' => $kernel,
        ], \JSON_THROW_ON_ERROR));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->configuration->load([['uri' => (new UriToPathConverter())->toUri($this->workspace->rootPath)]]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function escapingKernels(): iterable
    {
        yield 'parent traversal' => ['../outside/bin/console', 'inside each Symfony project'];
        yield 'Windows parent traversal' => ['bin\\..\\..\\outside\\console.php', 'inside each Symfony project'];
        yield 'Windows drive root' => ['C:\\outside\\bin\\console.php', 'inside each Symfony project'];
        yield 'Windows share root' => ['\\\\server\\share\\console.php', 'inside each Symfony project'];
        yield 'stream wrapper' => ['phar://outside/bin/console', 'inside each Symfony project'];
        yield 'null byte' => ["bin/console\0.php", 'kernel class name or a project-relative entry point path'];
    }
}
