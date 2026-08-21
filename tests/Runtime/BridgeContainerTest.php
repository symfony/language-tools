<?php

namespace Symfony\Lsp\Tests\Runtime;

final class BridgeContainerTest extends AbstractBridgeTestCase
{
    public function testNormalizesContainerMetadataWithoutExportingParameterValues(): void
    {
        $this->writeContainerApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=container 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        self::assertStringNotContainsString('CANARY_SECRET_', $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
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
