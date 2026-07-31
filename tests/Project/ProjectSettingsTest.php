<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Feature\Translation\TranslationConfigurationRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\ProjectSettings;
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
        $settings = new ProjectSettings($client, $projects, $configuration, $runtime);
        $settings->initialize(['capabilities' => ['workspace' => ['configuration' => true]]]);

        $settings->refresh();

        self::assertTrue($configuration->missingKeyDiagnostics($project));
        self::assertSame('test', $runtime->environment($project));
        self::assertSame([
            'items' => [[
                'scopeUri' => 'file:///workspace',
                'section' => 'symfonyLsp',
            ]],
        ], $client->params);
    }
}

final class ProjectSettingsClient implements ClientInterface
{
    /** @var array<array-key, mixed> */
    public array $params = [];

    public function request(string $method, array $params): mixed
    {
        $this->params = $params;

        return [['translationDiagnostics' => true, 'environment' => 'test']];
    }

    public function notify(string $method, array $params): void
    {
    }
}
