<?php

namespace Symfony\Lsp\Tests\Feature\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Console\ConsoleCommandMetadata;
use Symfony\Lsp\Feature\Console\ConsoleIndexRegistry;
use Symfony\Lsp\Feature\Console\ProjectConsoleSnapshotLoader;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class ProjectConsoleSnapshotLoaderTest extends TestCase
{
    public function testLoadsAndNormalizesCommandDefinitions(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $configuration = new RuntimeConfiguration();
        $configuration->configureProject($project, ['containerProjectRoot' => '/app']);
        $indexes = new ConsoleIndexRegistry();
        $loader = new ProjectConsoleSnapshotLoader($indexes, new ContainerPathMapper($configuration));
        $loader->load($project, ['sections' => ['console' => [
            'complete' => true,
            'commands' => [
                [
                    'class' => 'App\Command\ReportCommand',
                    'file' => '/app/src/Command/ReportCommand.php',
                    'arguments' => ['report', 42, 'report'],
                    'options' => ['verbose', null, 'format'],
                    'complete' => true,
                ],
                ['class' => 42, 'arguments' => ['invalid']],
                'malformed',
            ],
        ]]]);

        $index = $indexes->forProject($project);
        $command = $index->command('App\Command\ReportCommand');
        self::assertTrue($index->isComplete());
        self::assertInstanceOf(ConsoleCommandMetadata::class, $command);
        self::assertSame('/workspace/src/Command/ReportCommand.php', $command->file);
        self::assertSame(['report'], $command->arguments);
        self::assertSame(['format', 'verbose'], $command->options);
        self::assertTrue($command->complete);
        self::assertCount(1, $index->commands());
    }

    public function testIgnoresMalformedSections(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $indexes = new ConsoleIndexRegistry();
        $loader = new ProjectConsoleSnapshotLoader($indexes, new ContainerPathMapper(new RuntimeConfiguration()));
        $loader->load($project, ['sections' => ['console' => 'invalid']]);

        self::assertSame([], $indexes->forProject($project)->commands());
        self::assertFalse($indexes->forProject($project)->isComplete());
    }
}
