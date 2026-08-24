<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Feature\Translation\TranslationConfigurationRegistry;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectConfiguration;
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
        $settings = new ProjectSettings(
            $client,
            $projects,
            $configuration,
            $runtime,
            new ProjectConfiguration(new UriToPathConverter(), $analysisSettings),
            $analysisSettings,
        );
        $settings->initialize(['capabilities' => ['workspace' => ['configuration' => true]]]);

        $settings->refresh();

        self::assertTrue($configuration->missingKeyDiagnostics($project));
        self::assertSame('test', $runtime->environment($project));
        self::assertSame(120.0, $runtime->bridgeTimeout($project));
        self::assertSame([
            'items' => [[
                'scopeUri' => 'file:///workspace',
                'section' => 'symfonyLsp',
            ]],
        ], $client->params);
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
            ], \JSON_THROW_ON_ERROR));
            $projects = new ProjectRegistry();
            $projects->replace([$project = new Project($directory, 'file://'.$directory, '^8.0')]);
            $translation = new TranslationConfigurationRegistry();
            $runtime = new RuntimeConfiguration();
            $runtime->configure(['environment' => 'initialization']);
            $analysisSettings = new AnalysisSettings();
            $projectConfiguration = new ProjectConfiguration(new UriToPathConverter(), $analysisSettings);
            $projectConfiguration->load([['uri' => 'file://'.$directory]]);
            $settings = new ProjectSettings(
                new ProjectSettingsClient([['environment' => 'resource', 'translationDiagnostics' => false]]),
                $projects,
                $translation,
                $runtime,
                $projectConfiguration,
                $analysisSettings,
            );
            $settings->initialize(['capabilities' => ['workspace' => ['configuration' => true]]]);

            $settings->refresh();

            self::assertSame('resource', $runtime->environment($project));
            self::assertFalse($translation->missingKeyDiagnostics($project));
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
    public function __construct(private readonly array $response = [['translationDiagnostics' => true, 'environment' => 'test', 'bridgeTimeout' => 120]])
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
