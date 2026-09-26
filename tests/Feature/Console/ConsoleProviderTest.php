<?php

namespace Symfony\Lsp\Tests\Feature\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Console\ConsoleProvider;
use Symfony\Lsp\Tests\Support\LspRequests;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class ConsoleProviderTest extends TestCase
{
    public function testCompletesAndDiagnosesConsoleInputNames(): void
    {
        $uri = 'file:///workspace/src/Command/ReportCommand.php';
        $text = <<<'PHP'
            <?php
            use Symfony\Component\Console\Command\Command;
            use Symfony\Component\Console\Input\InputInterface;
            final class ReportCommand extends Command
            {
                protected function configure(): void
                {
                    $this->addArgument('report');
                    $this->addOption('format');
                }

                protected function execute(InputInterface $input): int
                {
                    $input->getArgument('report');
                    $input->getArgument('missing-argument');
                    $input->getOption('help');
                    $input->getOption('verbose');
                    $input->getOption('env');
                    $input->getOption('no-debug');
                    $input->getOption('missing-option');
                    $input->getOption($dynamic);
                    $other->getOption('unrelated');
                    return 0;
                }
            }
            PHP;
        $kit = $this->kit($uri, $text, [
            'class' => 'ReportCommand',
            'file' => '/workspace/src/Command/ReportCommand.php',
            'arguments' => ['command', 'report'],
            'options' => ['env', 'format', 'help', 'no-debug', 'verbose'],
            'complete' => true,
        ]);

        $diagnostics = $kit->get(ConsoleProvider::class)->diagnostics(LspRequests::document($uri));
        self::assertSame(['console.unknown_argument', 'console.unknown_option'], $kit->codes($diagnostics));
        self::assertSame([
            'Unknown Console input argument "missing-argument".',
            'Unknown Console input option "missing-option".',
        ], $kit->messages($diagnostics));

        $completionText = str_replace("getOption('missing-option')", "getOption('f')", $text);
        $completionKit = $this->kit($uri, $completionText, [
            'class' => 'ReportCommand',
            'file' => '/workspace/src/Command/ReportCommand.php',
            'arguments' => ['report'],
            'options' => ['format', 'help'],
            'complete' => true,
        ]);
        self::assertSame(['format'], $completionKit->labels($completionKit->get(ConsoleProvider::class)->complete($completionKit->positioned($completionKit->after($uri, "getOption('f")))));

        $sourceOnlyKit = $this->kit($uri, $completionText);
        self::assertSame(['format'], $sourceOnlyKit->labels($sourceOnlyKit->get(ConsoleProvider::class)->complete($sourceOnlyKit->positioned($sourceOnlyKit->after($uri, "getOption('f")))));
    }

    public function testSuppressesDiagnosticsForIncompleteExtensibleAndMissingRuntimeDefinitions(): void
    {
        $uri = 'file:///workspace/src/Command/DynamicCommand.php';
        $text = <<<'PHP'
            <?php
            use Symfony\Component\Console\Command\Command;
            use Symfony\Component\Console\Input\InputInterface;
            final class DynamicCommand extends Command
            {
                protected function configure(): void
                {
                    $this->addOption($dynamicName);
                }

                protected function execute(InputInterface $input): int
                {
                    $input->getOption('unknown');
                    return 0;
                }
            }
            PHP;
        $staticText = str_replace('$this->addOption($dynamicName);', '', $text);
        $extensible = ['class' => 'DynamicCommand', 'arguments' => [], 'options' => [], 'complete' => true];

        self::assertSame([], $this->kit($uri, $text, $extensible)->get(ConsoleProvider::class)->diagnostics(LspRequests::document($uri)));
        self::assertSame([], $this->kit($uri, $staticText, ['class' => 'DynamicCommand', 'arguments' => [], 'options' => [], 'complete' => false])->get(ConsoleProvider::class)->diagnostics(LspRequests::document($uri)));
        self::assertSame([], $this->kit($uri, $staticText)->get(ConsoleProvider::class)->diagnostics(LspRequests::document($uri)));
        self::assertSame([], $this->kit($uri, $staticText, $extensible, false)->get(ConsoleProvider::class)->diagnostics(LspRequests::document($uri)));
    }

    public function testCompletesInvokableAttributeAndAdaptedTraitInputNames(): void
    {
        $uri = 'file:///workspace/src/Command/ImportCommand.php';
        $text = <<<'PHP'
            <?php
            namespace App\Command;

            use Symfony\Component\Console\Attribute\Argument;
            use Symfony\Component\Console\Attribute\AsCommand;
            use Symfony\Component\Console\Attribute\Option;
            use Symfony\Component\Console\Input\InputInterface;

            trait SharedDefinition
            {
                protected function configure(): void
                {
                    $this->addArgument('shared');
                }

                public function report(): void
                {
                }
            }

            #[AsCommand]
            final class ImportCommand
            {
                use SharedDefinition {
                    report as importReport;
                }

                public function __invoke(
                    InputInterface $input,
                    #[\SensitiveParameter]
                    #[Argument]
                    string $sourcePath,
                    #[Deprecated, Option(name: 'dry-run')]
                    bool $dryRun = false,
                ): int {
                    $input->getOption('d');
                    $input->getArgument('s');

                    return 0;
                }
            }
            PHP;
        $kit = $this->kit($uri, $text);
        $provider = $kit->get(ConsoleProvider::class);

        self::assertSame(['dry-run'], $kit->labels($provider->complete($kit->positioned($kit->after($uri, "getOption('d")))));
        self::assertSame(['shared', 'source-path'], $kit->labels($provider->complete($kit->positioned($kit->after($uri, "getArgument('s")))));
    }

    public function testReturnsEmptyAndUnrelatedCompletionContextsPrecisely(): void
    {
        $uri = 'file:///workspace/src/Command/EmptyCommand.php';
        $text = <<<'PHP'
            <?php
            use Symfony\Component\Console\Command\Command;
            use Symfony\Component\Console\Input\InputInterface;
            final class EmptyCommand extends Command
            {
                public function execute(InputInterface $input, object $other): int
                {
                    $input->getArgument('z');
                    $other->getArgument('z');
                    return 0;
                }
            }
            PHP;
        $kit = $this->kit($uri, $text, ['class' => 'EmptyCommand', 'arguments' => [], 'options' => [], 'complete' => true]);
        $provider = $kit->get(ConsoleProvider::class);

        self::assertSame([], $provider->complete($kit->positioned($kit->after($uri, '$'."input->getArgument('z"))));
        self::assertSame([], $provider->complete($kit->positioned($kit->after($uri, '$'."other->getArgument('z"))));
    }

    /** @param array<string, mixed> $command */
    private function kit(string $uri, string $text, array $command = [], bool $runtimeComplete = true): ProjectTestKit
    {
        return (new ProjectTestKit())
            ->open($uri, $text)
            ->index()
            ->runtime('console', ['commands' => [] === $command ? [] : [$command], 'complete' => $runtimeComplete])
        ;
    }
}
