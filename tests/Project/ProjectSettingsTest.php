<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Feature\Translation\TranslationConfigurationRegistry;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\GlobPatternCompiler;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectConfiguration;
use Symfony\Lsp\Project\ProjectFileScopeRegistry;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\ProjectSettings;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class ProjectSettingsTest extends TestCase
{
    public function testLoadsResourceScopedTranslationDiagnostics(): void
    {
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $configuration = new TranslationConfigurationRegistry();
        $client = new ProjectSettingsClient();
        $runtime = new RuntimeConfiguration();
        $analysisSettings = new AnalysisSettings();
        $fileScope = new ProjectFileScopeRegistry(new GlobPatternCompiler());
        $settings = new ProjectSettings(
            $client,
            $projects,
            $configuration,
            $runtime,
            new ProjectConfiguration(new UriToPathConverter(), $analysisSettings),
            $fileScope,
            $analysisSettings,
        );
        $settings->initialize(['capabilities' => ['workspace' => ['configuration' => true]]]);

        $settings->refresh();

        self::assertTrue($configuration->missingKeyDiagnostics($project));
        self::assertSame('test', $runtime->environment($project));
        self::assertSame(120.0, $runtime->bridgeTimeout($project));
        self::assertTrue($fileScope->isExcluded($project, '/workspace/tests/Fixtures/Rule.php'));
        self::assertSame([
            'items' => [[
                'scopeUri' => 'file:///workspace',
                'section' => 'symfonyLsp',
            ]],
        ], $client->params);
    }

    public function testPhpCommandSettingsOverrideTheSymfonyCliDefault(): void
    {
        $directory = sys_get_temp_dir().'/symfony-lsp-project-settings-'.bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            file_put_contents($directory.'/.symfony-lsp.json', json_encode([
                'version' => 1,
                'phpCommand' => ['project-php'],
            ], \JSON_THROW_ON_ERROR));
            $projects = new ProjectRegistry();
            $projects->replace([$project = new Project($directory, 'file://'.$directory, '^8.0')]);
            $runtime = new RuntimeConfiguration(defaultPhpCommand: ['/usr/local/bin/symfony', 'php']);
            $analysisSettings = new AnalysisSettings();
            $projectConfiguration = new ProjectConfiguration(new UriToPathConverter(), $analysisSettings);
            $projectConfiguration->load([['uri' => 'file://'.$directory]]);
            $settings = new ProjectSettings(
                new ProjectSettingsClient([]),
                $projects,
                new TranslationConfigurationRegistry(),
                $runtime,
                $projectConfiguration,
                new ProjectFileScopeRegistry(new GlobPatternCompiler()),
                $analysisSettings,
            );

            self::assertSame(['/usr/local/bin/symfony', 'php'], $runtime->phpCommand($project));

            $settings->applyFileSettings();
            self::assertSame(['project-php'], $runtime->phpCommand($project));

            $runtime->configure(['phpCommand' => ['initialization-php']]);
            $settings->applyFileSettings();
            self::assertSame(['initialization-php'], $runtime->phpCommand($project));

            $settings->applyFileSettings(['phpCommand' => ['command-line-php']]);
            self::assertSame(['command-line-php'], $runtime->phpCommand($project));
        } finally {
            (new Filesystem())->remove($directory);
        }
    }

    public function testResourceSettingsOverrideInitializationAndCheckedInSettings(): void
    {
        $directory = sys_get_temp_dir().'/symfony-lsp-project-settings-'.bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            file_put_contents($directory.'/.symfony-lsp.json', json_encode([
                'version' => 1,
                'environment' => 'file',
                'translationDiagnostics' => true,
                'excludePaths' => ['tests/**'],
            ], \JSON_THROW_ON_ERROR));
            $projects = new ProjectRegistry();
            $projects->replace([$project = new Project($directory, 'file://'.$directory, '^8.0')]);
            $translation = new TranslationConfigurationRegistry();
            $runtime = new RuntimeConfiguration();
            $runtime->configure(['environment' => 'initialization']);
            $analysisSettings = new AnalysisSettings();
            $projectConfiguration = new ProjectConfiguration(new UriToPathConverter(), $analysisSettings);
            $projectConfiguration->load([['uri' => 'file://'.$directory]]);
            $fileScope = new ProjectFileScopeRegistry(new GlobPatternCompiler());
            $settings = new ProjectSettings(
                new ProjectSettingsClient([['environment' => 'resource', 'translationDiagnostics' => false, 'excludePaths' => ['fixtures/**']]]),
                $projects,
                $translation,
                $runtime,
                $projectConfiguration,
                $fileScope,
                $analysisSettings,
            );
            $settings->initialize(['capabilities' => ['workspace' => ['configuration' => true]]]);

            $settings->refresh();

            self::assertSame('resource', $runtime->environment($project));
            self::assertFalse($translation->missingKeyDiagnostics($project));
            self::assertFalse($fileScope->isExcluded($project, $directory.'/tests/Rule.php'));
            self::assertTrue($fileScope->isExcluded($project, $directory.'/fixtures/Rule.php'));
        } finally {
            (new Filesystem())->remove($directory);
        }
    }
}

final class ProjectSettingsClient implements ClientInterface
{
    /** @var array<array-key, mixed> */
    public array $params = [];

    /** @param array<array-key, mixed> $response */
    public function __construct(private readonly array $response = [['translationDiagnostics' => true, 'environment' => 'test', 'bridgeTimeout' => 120, 'excludePaths' => ['tests/Fixtures/**']]])
    {
    }

    public function request(string $method, array $params): mixed
    {
        $this->params = $params;

        return $this->response;
    }

    public function notify(string $method, array $params): void
    {
    }
}
