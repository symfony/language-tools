<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\AnalysisSettingsRegistry;
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
use Symfony\Lsp\Project\ProjectWorkspace;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Tests\Support\RecordingClient;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class ProjectWorkspaceTest extends TestCase
{
    private TestWorkspace $workspace;
    private ProjectRegistry $registry;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-workspace-');
        $this->workspace->write('composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));
        $this->registry = new ProjectRegistry();
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testDiscoversTheConfiguredAndAutomaticProjectsOfEveryFolder(): void
    {
        $this->workspace->write('apps/admin/composer.json', json_encode([
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));
        $this->workspace->write('.symfony-lsp.json', json_encode([
            'version' => 1,
            'projectRoots' => ['.', 'apps/admin'],
        ], \JSON_THROW_ON_ERROR));
        $workspace = $this->projectWorkspace();
        $workspace->configure($this->folders());

        $projects = $workspace->discover();

        self::assertSame(
            [$this->workspace->rootPath, $this->workspace->path('apps/admin')],
            array_map(static fn (Project $project): string => $project->rootPath, $projects),
        );
        self::assertSame($projects, $this->registry->all());
    }

    public function testRejectsConfiguredRootsThatResolveOutsideTheWorkspace(): void
    {
        $external = new TestWorkspace('symfony-lsp-external-');
        try {
            if (!@symlink($external->rootPath, $this->workspace->path('linked'))) {
                self::markTestSkipped('The platform cannot create directory symlinks.');
            }
            $this->workspace->write('.symfony-lsp.json', json_encode([
                'version' => 1,
                'projectRoots' => ['linked'],
            ], \JSON_THROW_ON_ERROR));
            $workspace = $this->projectWorkspace();
            $workspace->configure($this->folders());

            $this->expectException(InvalidConfigurationException::class);
            $this->expectExceptionMessage('The project root "linked" is outside the workspace.');

            $workspace->discover();
        } finally {
            $external->cleanup();
        }
    }

    public function testRejectsExplicitRootsThatAreNotSymfonyProjects(): void
    {
        $this->workspace->write('library/composer.json', json_encode(['name' => 'acme/library'], \JSON_THROW_ON_ERROR));
        $workspace = $this->projectWorkspace();
        $workspace->configure($this->folders(), projectRoots: ['library']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The project root "library" was not discovered as a Symfony project.');

        $workspace->discover();
    }

    public function testReleasesTheStateOfProjectsThatAreNoLongerRegistered(): void
    {
        $nested = $this->workspace->write('nested/composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));
        $state = new RecordingProjectState();
        $workspace = $this->projectWorkspace($state);
        $workspace->configure($this->folders());
        $workspace->discover();

        unlink($nested);
        $workspace->discover();

        self::assertSame([$this->workspace->path('nested')], $state->removed);
    }

    /** @return list<array{uri: string, name?: string}> */
    private function folders(): array
    {
        return [['uri' => (new UriToPathConverter())->toUri($this->workspace->rootPath)]];
    }

    private function projectWorkspace(?RecordingProjectState $state = null): ProjectWorkspace
    {
        $uriToPathConverter = new UriToPathConverter();
        $analysisSettings = new AnalysisSettings();
        $projectConfiguration = new ProjectConfiguration($uriToPathConverter, $analysisSettings);
        $settings = new AnalysisSettingsRegistry();

        return new ProjectWorkspace(
            $projectConfiguration,
            new ProjectDiscovery($uriToPathConverter, new GitignoreMatcher()),
            $this->registry,
            new ProjectSettings(
                new RecordingClient(),
                $this->registry,
                $settings,
                $projectConfiguration,
                new ProjectFileScopeRegistry(new GlobPatternCompiler()),
                $analysisSettings,
            ),
            new ProjectStateCleaner(null === $state ? [] : [$state]),
            $settings,
            $uriToPathConverter,
        );
    }
}
