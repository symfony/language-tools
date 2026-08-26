<?php

namespace Symfony\Lsp\Tests\Feature\Console;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Console\ConsoleExtractor;
use Symfony\Lsp\Feature\Console\ConsoleInputKind;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\QuotedArgumentMatcher;

final class ConsoleExtractorTest extends TestCase
{
    public function testExtractsDefinitionsReferencesTraitsAndInvokableAttributes(): void
    {
        $text = <<<'PHP'
            <?php
            namespace App\Command;

            use Symfony\Component\Console\Attribute\Argument;
            use Symfony\Component\Console\Attribute\AsCommand;
            use Symfony\Component\Console\Attribute\Option;
            use Symfony\Component\Console\Command\Command;
            use Symfony\Component\Console\Input\InputArgument;
            use Symfony\Component\Console\Input\InputDefinition;
            use Symfony\Component\Console\Input\InputInterface;
            use Symfony\Component\Console\Input\InputOption;

            trait SharedDefinition
            {
                protected function configure(): void
                {
                    $this->addArgument('shared');
                }
            }

            final class ReportCommand extends Command
            {
                use SharedDefinition;

                protected function configure(): void
                {
                    $this
                        ->addArgument('report\\name')
                        ->addOption("out\"put")
                    ;
                    $this->setDefinition(new InputDefinition([
                        new InputArgument('format'),
                        new InputOption('color'),
                    ]));
                }

                protected function execute(InputInterface $input): int
                {
                    $input->getArgument('report\\name');
                    $input->getOption("out\"put");
                    $input->getOption($dynamic);
                    $other->getOption('unrelated');
                    // $input->getOption('commented');

                    return 0;
                }
            }

            abstract class BaseInvokableCommand extends Command
            {
            }

            final class ImportCommand extends BaseInvokableCommand
            {
                public function __invoke(
                    #[Argument] string $sourcePath,
                    #[Option(name: 'dry-run')] bool $dryRun,
                    #[Option('Description', 'output-format')] string $format,
                ): int {
                    return 0;
                }
            }
            PHP;

        $facts = $this->extractor()->extract('file:///workspace/src/Command/ReportCommand.php', 'php', $text);
        $declarations = [];
        foreach ($facts->declarations() as $declaration) {
            $declarations[$declaration->className()] = $declaration;
        }

        self::assertSame(['shared'], $declarations['App\Command\SharedDefinition']->arguments());
        self::assertSame(['format', 'report\name'], $declarations['App\Command\ReportCommand']->arguments());
        self::assertSame(['color', 'out"put'], $declarations['App\Command\ReportCommand']->options());
        self::assertSame(['App\Command\SharedDefinition'], $declarations['App\Command\ReportCommand']->traits());
        self::assertTrue($declarations['App\Command\ReportCommand']->isComplete());
        self::assertSame(['source-path'], $declarations['App\Command\ImportCommand']->arguments());
        self::assertSame(['dry-run', 'output-format'], $declarations['App\Command\ImportCommand']->options());

        self::assertSame(['report\name', 'out"put'], array_map(static fn ($reference): string => $reference->name(), $facts->references()));
        self::assertSame([ConsoleInputKind::Argument, ConsoleInputKind::Option], array_map(static fn ($reference): ConsoleInputKind => $reference->kind(), $facts->references()));
    }

    public function testMarksDynamicDefinitionsIncomplete(): void
    {
        $facts = $this->extractor()->extract('file:///workspace/src/Command/DynamicCommand.php', 'php', <<<'PHP'
            <?php
            use Symfony\Component\Console\Attribute\AsCommand;
            use Symfony\Component\Console\Attribute\Option;
            use Symfony\Component\Console\Command\Command;
            final class DynamicCommand extends Command
            {
                protected function configure(): void
                {
                    $this->addArgument($name);
                    $this->setDefinition(buildDefinition());
                }
            }

            #[AsCommand]
            final class DynamicInvokableCommand
            {
                public function __invoke(#[Option(name: DYNAMIC_NAME)] bool $enabled = false): int
                {
                    return 0;
                }
            }
            PHP);

        self::assertFalse($facts->declarations()[0]->isComplete());
        self::assertFalse($facts->declarations()[1]->isComplete());
    }

    public function testCompletesOnlyInputInterfaceReceiversWithIncompleteSyntax(): void
    {
        $text = <<<'PHP'
            <?php
            use Symfony\Component\Console\Command\Command;
            use Symfony\Component\Console\Input\InputInterface;
            final class DemoCommand extends Command
            {
                public function execute(InputInterface $input): int
                {
                    $input->getOption('ver|
                }
            }
            PHP;
        $cursor = strpos($text, '|');
        self::assertIsInt($cursor);
        $text = str_replace('|', '', $text);
        $context = $this->extractor()->completionContext('php', $text, $cursor);

        self::assertSame(ConsoleInputKind::Option, $context?->kind());
        self::assertSame('ver', $context->prefix());
        self::assertSame('DemoCommand', $context->commandClass());

        $unrelated = str_replace('InputInterface $input', 'object $input', $text);
        self::assertNull($this->extractor()->completionContext('php', $unrelated, $cursor));
    }

    private function extractor(): ConsoleExtractor
    {
        $converter = new PositionConverter();

        return new ConsoleExtractor(
            $converter,
            new TolerantPhpParser(new Parser()),
            new QuotedArgumentMatcher($converter),
            new PhpCommentParser(),
        );
    }
}
