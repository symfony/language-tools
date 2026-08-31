<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\Bridge\BridgeFixtureWorkspace;
use Symfony\Lsp\Tests\Support\Bridge\BridgeProcessFixture;
use Symfony\Lsp\Tests\Support\Bridge\ContainerFixtureBuilder;

final class BridgeContainerTest extends TestCase
{
    private BridgeFixtureWorkspace $workspace;
    private BridgeProcessFixture $bridge;

    protected function setUp(): void
    {
        $this->workspace = new BridgeFixtureWorkspace();
        $this->bridge = new BridgeProcessFixture($this->workspace->path);
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testNormalizesContainerMetadataWithoutExportingParameterValues(): void
    {
        (new ContainerFixtureBuilder($this->workspace))->writeContainerApplication();

        $process = $this->bridge->run(['--sections=container']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        self::assertStringNotContainsString('CANARY_SECRET_', $process->stdout);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors']);
        self::assertIsArray($result['sections']);
        self::assertIsArray($result['sections']['container']);
        self::assertFalse($result['sections']['container']['servicesComplete'] ?? null);
        self::assertTrue($result['sections']['container']['parametersComplete'] ?? null);
        self::assertSame([
            [
                'id' => 'app.mailer',
                'class' => 'App\\Mailer',
                'alias' => null,
                'public' => false,
                'lazy' => true,
                'deprecation' => 'Use app.new_mailer instead.',
                'tags' => ['kernel.reset', 'monolog.logger'],
                'decorates' => 'mailer',
                'decorationStack' => ['app.mailer', 'mailer.inner'],
                'autowiringTypes' => ['App\\MailerInterface'],
            ],
            [
                'id' => 'mailer',
                'class' => null,
                'alias' => 'app.mailer',
                'public' => true,
                'lazy' => null,
                'deprecation' => null,
                'tags' => [],
                'decorates' => null,
                'decorationStack' => [],
                'autowiringTypes' => [],
            ],
        ], $result['sections']['container']['items']);
        self::assertSame([
            ['name' => 'app.api_key', 'deprecation' => null],
            ['name' => 'app.storage_dir', 'deprecation' => 'Since symfony/dependency-injection 8.0: Use app.data_dir.'],
            ['name' => 'app.structured', 'deprecation' => null],
        ], $result['sections']['container']['parameters']);
    }
}
