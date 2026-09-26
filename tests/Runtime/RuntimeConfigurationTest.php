<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Project\ProjectAnalysisSettings;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Tests\Support\RuntimeSettings;

final class RuntimeConfigurationTest extends TestCase
{
    public function testUsesTheConfiguredDefaultPhpCommand(): void
    {
        $settings = RuntimeSettings::registry();
        $configuration = new RuntimeConfiguration($settings, defaultPhpCommand: ['/usr/local/bin/symfony', 'php']);

        self::assertSame(['/usr/local/bin/symfony', 'php'], $configuration->phpCommand());

        $settings->configureWorkspace(new ProjectAnalysisSettings(phpCommand: ['initialization-php']));

        self::assertSame(['initialization-php'], $configuration->phpCommand());
    }

    public function testReleaseMetadataAccessCanBeDisabled(): void
    {
        $configuration = new RuntimeConfiguration($settings = RuntimeSettings::registry());

        self::assertTrue($configuration->releaseMetadata());

        $settings->configureWorkspace(new ProjectAnalysisSettings(releaseMetadata: false));

        self::assertFalse($configuration->releaseMetadata());
    }

    /** @param array<array-key, mixed> $command */
    #[DataProvider('invalidDefaultPhpCommandProvider')]
    public function testRejectsInvalidDefaultPhpCommands(array $command): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('phpCommand');

        new RuntimeConfiguration(defaultPhpCommand: $command);
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidDefaultPhpCommandProvider(): iterable
    {
        yield 'empty' => [[]];
        yield 'empty argument' => [['php', '']];
        yield 'non-string argument' => [['php', 1]];
        yield 'non-list' => [['command' => 'php']];
    }
}
