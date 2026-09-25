<?php

namespace Symfony\Lsp\Tests\Feature\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Console\ConsoleCommandMetadata;
use Symfony\Lsp\Feature\Console\ConsoleIndexRegistry;
use Symfony\Lsp\Feature\Console\ProjectConsoleSnapshotLoader;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Tests\Support\SnapshotSections;

final class ProjectConsoleSnapshotLoaderTest extends TestCase
{
    public function testLoadsCommandDefinitions(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $configuration = new RuntimeConfiguration();
        $configuration->configureProject($project, ['containerProjectRoot' => '/app']);
        $indexes = new ConsoleIndexRegistry();
        $loader = new ProjectConsoleSnapshotLoader($indexes);
        $loader->load($project, SnapshotSections::of($project, [
            'complete' => true,
            'commands' => [
                [
                    'class' => 'App\Command\ReportCommand',
                    'file' => '/app/src/Command/ReportCommand.php',
                    'arguments' => ['report', 42],
                    'options' => ['format', null, 'verbose'],
                    'complete' => true,
                ],
                ['class' => 42, 'arguments' => ['invalid']],
                'malformed',
            ],
        ], $configuration));

        $index = $indexes->forProject($project);
        $command = $index->command('App\Command\ReportCommand');
        self::assertTrue($index->isComplete());
        self::assertInstanceOf(ConsoleCommandMetadata::class, $command);
        self::assertSame('/workspace/src/Command/ReportCommand.php', $command->file);
        self::assertSame(['report'], $command->arguments);
        self::assertSame(['format', 'verbose'], $command->options);
        self::assertTrue($command->complete);
    }

    public function testIgnoresMalformedCommands(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $indexes = new ConsoleIndexRegistry();
        $loader = new ProjectConsoleSnapshotLoader($indexes);
        $loader->load($project, SnapshotSections::of($project, ['commands' => 'invalid']));

        self::assertNull($indexes->forProject($project)->command('invalid'));
        self::assertFalse($indexes->forProject($project)->isComplete());
    }
}
