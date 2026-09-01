<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectConfiguration;
use Symfony\Lsp\Project\UriToPathConverter;

final class ProjectConfigurationTest extends TestCase
{
    private string $directory;
    private ProjectConfiguration $configuration;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/symfony-lsp-config-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/apps/admin', 0777, true);
        $this->configuration = new ProjectConfiguration(new UriToPathConverter(), new AnalysisSettings());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testLoadsWorkspaceDefaultsAndProjectOverrides(): void
    {
        file_put_contents($this->directory.'/.symfony-lsp.json', json_encode([
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
        $this->configuration->load([['uri' => (new UriToPathConverter())->toUri($this->directory)]]);
        $project = new Project($this->directory.'/apps/admin', 'file:///workspace/apps/admin', '^8.0');

        self::assertSame([$this->directory, $this->directory.'/apps/admin'], $this->configuration->projectRoots($this->directory));
        self::assertSame([
            'environment' => 'admin',
            'bridgeTimeout' => 90.0,
            'releaseMetadata' => false,
            'excludePaths' => ['tests/Fixtures/**', 'var/generated/**'],
            'translationDiagnostics' => true,
        ], $this->configuration->settings($project));
        self::assertSame('apps/admin', $this->configuration->projectId($project));
        self::assertSame('apps/admin/config/services.yaml', $this->configuration->workspaceRelativePath($project, $project->rootPath.'/config/services.yaml'));
    }

    public function testRejectsProjectRootsThatResolveOutsideTheWorkspace(): void
    {
        $external = $this->directory.'-external';
        mkdir($external);
        try {
            if (!@symlink($external, $this->directory.'/linked')) {
                self::markTestSkipped('The platform cannot create directory symlinks.');
            }
            file_put_contents($this->directory.'/.symfony-lsp.json', json_encode([
                'version' => 1,
                'projectRoots' => ['linked'],
            ], \JSON_THROW_ON_ERROR));

            $this->expectException(InvalidConfigurationException::class);
            $this->expectExceptionMessage('outside the workspace');

            $this->configuration->load([['uri' => (new UriToPathConverter())->toUri($this->directory)]]);
        } finally {
            @rmdir($external);
        }
    }

    public function testRejectsProjectOverridesThatDoNotMatchDiscoveredProjects(): void
    {
        file_put_contents($this->directory.'/.symfony-lsp.json', json_encode([
            'version' => 1,
            'projects' => [
                'apps/admin' => ['environment' => 'admin'],
            ],
        ], \JSON_THROW_ON_ERROR));
        $this->configuration->load([['uri' => (new UriToPathConverter())->toUri($this->directory)]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('apps/admin');

        $this->configuration->validateProjects([]);
    }

    public function testRejectsExcludePathsOutsideProjects(): void
    {
        file_put_contents($this->directory.'/.symfony-lsp.json', json_encode([
            'version' => 1,
            'excludePaths' => ['../outside/**'],
        ], \JSON_THROW_ON_ERROR));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('inside each Symfony project');

        $this->configuration->load([['uri' => (new UriToPathConverter())->toUri($this->directory)]]);
    }

    public function testRejectsUnknownAndInvalidOptions(): void
    {
        file_put_contents($this->directory.'/.symfony-lsp.json', json_encode([
            'version' => 1,
            'bridgeTimeout' => 0,
        ], \JSON_THROW_ON_ERROR));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('bridgeTimeout');

        $this->configuration->load([['uri' => (new UriToPathConverter())->toUri($this->directory)]]);
    }
}
