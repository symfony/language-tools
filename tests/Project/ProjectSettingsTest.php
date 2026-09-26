<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\AnalysisSettingsRegistry;
use Symfony\Lsp\Project\GlobPatternCompiler;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectAnalysisSettings;
use Symfony\Lsp\Project\ProjectConfiguration;
use Symfony\Lsp\Project\ProjectFileScopeRegistry;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\ProjectSettings;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Tests\Support\RecordingClient;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class ProjectSettingsTest extends TestCase
{
    public function testLoadsResourceScopedTranslationDiagnostics(): void
    {
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $client = new RecordingClient([['translationDiagnostics' => true, 'environment' => 'test', 'bridgeTimeout' => 120, 'excludePaths' => ['tests/Fixtures/**']]]);
        $analysisSettings = new AnalysisSettings();
        $registry = new AnalysisSettingsRegistry();
        $runtime = new RuntimeConfiguration($registry);
        $fileScope = new ProjectFileScopeRegistry(new GlobPatternCompiler());
        $settings = new ProjectSettings(
            $client,
            $projects,
            $registry,
            new ProjectConfiguration(new UriToPathConverter(), $analysisSettings),
            $fileScope,
            $analysisSettings,
        );
        $settings->initialize(['capabilities' => ['workspace' => ['configuration' => true]]]);

        $settings->refresh();

        self::assertTrue($registry->forProject($project)->translationDiagnostics);
        self::assertSame('test', $runtime->environment($project));
        self::assertSame(120.0, $runtime->bridgeTimeout($project));
        self::assertTrue($fileScope->isExcluded($project, '/workspace/tests/Fixtures/Rule.php'));
        self::assertSame([
            'items' => [[
                'scopeUri' => 'file:///workspace',
                'section' => 'symfonyLsp',
            ]],
        ], $client->requests[0]['params'] ?? null);
    }

    public function testRejectsAnInvalidEditorSettingWhereItComesFromAndKeepsTheOthers(): void
    {
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $analysisSettings = new AnalysisSettings();
        $registry = new AnalysisSettingsRegistry();
        $runtime = new RuntimeConfiguration($registry);
        $settings = new ProjectSettings(
            new RecordingClient([['environment' => "test\n", 'bridgeTimeout' => 120]]),
            $projects,
            $registry,
            new ProjectConfiguration(new UriToPathConverter(), $analysisSettings),
            new ProjectFileScopeRegistry(new GlobPatternCompiler()),
            $analysisSettings,
        );
        $settings->initialize(['capabilities' => ['workspace' => ['configuration' => true]]]);

        $settings->refresh();

        self::assertNull($registry->forProject($project)->environment);
        self::assertSame('dev', $runtime->environment($project));
        self::assertSame(120.0, $runtime->bridgeTimeout($project));
    }

    public function testPhpCommandSettingsOverrideTheSymfonyCliDefault(): void
    {
        $workspace = new TestWorkspace('symfony-lsp-project-settings-');
        $directory = $workspace->rootPath;
        try {
            $workspace->write('.symfony-lsp.json', json_encode([
                'version' => 1,
                'phpCommand' => ['project-php'],
            ], \JSON_THROW_ON_ERROR));
            $projects = new ProjectRegistry();
            $projects->replace([$project = new Project($directory, 'file://'.$directory)]);
            $analysisSettings = new AnalysisSettings();
            $registry = new AnalysisSettingsRegistry();
            $runtime = new RuntimeConfiguration($registry, defaultPhpCommand: ['/usr/local/bin/symfony', 'php']);
            $projectConfiguration = new ProjectConfiguration(new UriToPathConverter(), $analysisSettings);
            $projectConfiguration->load([['uri' => 'file://'.$directory]]);
            $settings = new ProjectSettings(
                new RecordingClient([]),
                $projects,
                $registry,
                $projectConfiguration,
                new ProjectFileScopeRegistry(new GlobPatternCompiler()),
                $analysisSettings,
            );

            self::assertSame(['/usr/local/bin/symfony', 'php'], $runtime->phpCommand($project));

            $settings->applyFileSettings();
            self::assertSame(['project-php'], $runtime->phpCommand($project));

            $registry->configureWorkspace(new ProjectAnalysisSettings(phpCommand: ['initialization-php']));
            $settings->applyFileSettings();
            self::assertSame(['initialization-php'], $runtime->phpCommand($project));

            $registry->configureWorkspace(new ProjectAnalysisSettings(phpCommand: ['command-line-php']));
            $settings->applyFileSettings();
            self::assertSame(['command-line-php'], $runtime->phpCommand($project));
        } finally {
            $workspace->cleanup();
        }
    }

    public function testResourceSettingsOverrideInitializationAndCheckedInSettings(): void
    {
        $workspace = new TestWorkspace('symfony-lsp-project-settings-');
        $directory = $workspace->rootPath;
        try {
            $workspace->write('.symfony-lsp.json', json_encode([
                'version' => 1,
                'environment' => 'file',
                'translationDiagnostics' => true,
                'excludePaths' => ['tests/**'],
            ], \JSON_THROW_ON_ERROR));
            $projects = new ProjectRegistry();
            $projects->replace([$project = new Project($directory, 'file://'.$directory)]);
            $analysisSettings = new AnalysisSettings();
            $registry = new AnalysisSettingsRegistry();
            $registry->configureWorkspace(new ProjectAnalysisSettings(environment: 'initialization'));
            $runtime = new RuntimeConfiguration($registry);
            $projectConfiguration = new ProjectConfiguration(new UriToPathConverter(), $analysisSettings);
            $projectConfiguration->load([['uri' => 'file://'.$directory]]);
            $fileScope = new ProjectFileScopeRegistry(new GlobPatternCompiler());
            $settings = new ProjectSettings(
                new RecordingClient([['environment' => 'resource', 'translationDiagnostics' => false, 'excludePaths' => ['fixtures/**']]]),
                $projects,
                $registry,
                $projectConfiguration,
                $fileScope,
                $analysisSettings,
            );
            $settings->initialize(['capabilities' => ['workspace' => ['configuration' => true]]]);

            $settings->refresh();

            self::assertSame('resource', $runtime->environment($project));
            self::assertFalse($registry->forProject($project)->translationDiagnostics);
            self::assertFalse($fileScope->isExcluded($project, $directory.'/tests/Rule.php'));
            self::assertTrue($fileScope->isExcluded($project, $directory.'/fixtures/Rule.php'));
        } finally {
            $workspace->cleanup();
        }
    }
}
