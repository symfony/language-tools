<?php

namespace Symfony\Lsp\Tests\Feature\Console;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Console\ConsoleExtractor;
use Symfony\Lsp\Feature\Console\ConsoleInputKind;
use Symfony\Lsp\Parser\Php\LastResultPhpParser;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpExpressionParser;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;

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
                        /* keep the chain readable */
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
        foreach ($facts->declarations as $declaration) {
            $declarations[$declaration->className] = $declaration;
        }

        self::assertSame(['shared'], $declarations['App\Command\SharedDefinition']->arguments);
        self::assertSame(['format', 'report\name'], $declarations['App\Command\ReportCommand']->arguments);
        self::assertSame(['color', 'out"put'], $declarations['App\Command\ReportCommand']->options);
        self::assertSame(['App\Command\SharedDefinition'], $declarations['App\Command\ReportCommand']->traits);
        self::assertTrue($declarations['App\Command\ReportCommand']->complete);
        self::assertSame(['source-path'], $declarations['App\Command\ImportCommand']->arguments);
        self::assertSame(['dry-run', 'output-format'], $declarations['App\Command\ImportCommand']->options);

        self::assertSame(['report\name', 'out"put'], array_map(static fn ($reference): string => $reference->name, $facts->references));
        self::assertSame([ConsoleInputKind::Argument, ConsoleInputKind::Option], array_map(static fn ($reference): ConsoleInputKind => $reference->kind, $facts->references));
    }

    public function testScopesInputReferencesToTheirOwningMethods(): void
    {
        $facts = $this->extractor()->extract('file:///workspace/src/Command/ScopedCommand.php', 'php', <<<'PHP'
            <?php
            use Symfony\Component\Console\Input\InputInterface;

            final class ScopedCommand
            {
                public function first(InputInterface $input): void
                {
                    $input->getArgument('tracked');
                }

                public function second(object $input): void
                {
                    $input->getArgument('ignored');
                }
            }
            PHP);

        self::assertSame(['tracked'], array_map(static fn ($reference): string => $reference->name, $facts->references));
        self::assertSame(['ScopedCommand'], array_map(static fn ($reference): string => $reference->commandClass, $facts->references));
    }

    public function testKeepsDeclarationsCallsAndAttributesWithTheirOwningTypes(): void
    {
        $facts = $this->extractor()->extract('file:///workspace/src/Command/Declarations.php', 'php', <<<'PHP'
            <?php
            namespace App\Command;

            use Symfony\Component\Console\Attribute\AsCommand;

            trait SharedDefinition
            {
                protected function configure(): void
                {
                    $this->addArgument('shared');
                }
            }

            interface CommandContract
            {
                public function run(): void;
            }

            enum CommandState
            {
                case Ready;

                protected function configure(): void
                {
                    $this->addArgument('enum');
                }
            }

            #[AsCommand]
            final class FirstCommand
            {
                protected function configure(): void
                {
                    $this->addArgument('first');
                }
            }

            final class NeighborCommand
            {
                #[AsCommand]
                protected function configure(): void
                {
                    $this->addArgument('neighbor');
                }
            }
            PHP);
        $declarations = [];
        foreach ($facts->declarations as $declaration) {
            $declarations[$declaration->className] = $declaration;
        }

        self::assertSame([
            'App\Command\SharedDefinition',
            'App\Command\FirstCommand',
            'App\Command\NeighborCommand',
        ], array_keys($declarations));
        self::assertSame(['shared'], $declarations['App\Command\SharedDefinition']->arguments);
        self::assertSame(['first'], $declarations['App\Command\FirstCommand']->arguments);
        self::assertSame(['neighbor'], $declarations['App\Command\NeighborCommand']->arguments);
        self::assertTrue($declarations['App\Command\FirstCommand']->command);
        self::assertFalse($declarations['App\Command\NeighborCommand']->command);
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

        self::assertFalse($facts->declarations[0]->complete);
        self::assertFalse($facts->declarations[1]->complete);
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

        self::assertSame(ConsoleInputKind::Option, $context?->kind);
        self::assertSame('ver', $context->prefix);
        self::assertSame('DemoCommand', $context->commandClass);

        $unrelated = str_replace('InputInterface $input', 'object $input', $text);
        self::assertNull($this->extractor()->completionContext('php', $unrelated, $cursor));
    }

    public function testScopesIncompleteCompletionReceiversToTheirOwningMethods(): void
    {
        $text = <<<'PHP'
            <?php
            use Symfony\Component\Console\Input\InputInterface;

            final class DemoCommand
            {
                public function first(InputInterface $input): void
                {
                }

                public function second(object $input): void
                {
                    $input->getOption('ver|
                }
            }
            PHP;
        $cursor = strpos($text, '|');
        self::assertIsInt($cursor);
        $text = str_replace('|', '', $text);

        self::assertNull($this->extractor()->completionContext('php', $text, $cursor));
    }

    public function testMarksUnterminatedConfigureIncomplete(): void
    {
        $facts = $this->extractor()->extract('file:///workspace/src/Command/DraftCommand.php', 'php', <<<'PHP'
            <?php
            final class DraftCommand
            {
                protected function configure(): void
                {
                    $this->addArgument('draft');
            PHP);

        self::assertFalse($facts->declarations[0]->complete);
    }

    public function testKeepsDocumentParseCachedWhileParsingDefinitionExpressions(): void
    {
        $text = <<<'PHP'
            <?php
            use Symfony\Component\Console\Input\InputArgument;

            final class ReportCommand
            {
                /** Configures report input. */
                protected function configure(): void
                {
                    $this->setDefinition([
                        // The expression parser must accept comments.
                        new InputArgument('report'),
                    ]);
                }
            }
            PHP;
        $inner = new CountingConsolePhpParser(new TolerantPhpParser(new Parser()));
        $parser = new LastResultPhpParser($inner);
        $expressionParser = new CountingConsolePhpParser(new TolerantPhpParser(new Parser()));
        $converter = new PositionConverter();
        $extractor = new ConsoleExtractor(
            $converter,
            $parser,
            new PhpExpressionParser($expressionParser),
            new PhpCommentParser(),
        );

        $facts = $extractor->extract('file:///workspace/src/Command/ReportCommand.php', 'php', $text);
        $parser->parse($text);

        self::assertSame(['report'], $facts->declarations[0]->arguments);
        self::assertSame([$text], $inner->sources);
        self::assertCount(1, $expressionParser->sources);
        self::assertStringContainsString('// The expression parser must accept comments.', $expressionParser->sources[0]);
    }

    private function extractor(): ConsoleExtractor
    {
        $converter = new PositionConverter();

        return new ConsoleExtractor(
            $converter,
            new TolerantPhpParser(new Parser()),
            new PhpExpressionParser(new TolerantPhpParser(new Parser())),
            new PhpCommentParser(),
        );
    }
}

final class CountingConsolePhpParser implements PhpParserInterface
{
    /** @var list<string> */
    public array $sources = [];

    public function __construct(private readonly PhpParserInterface $parser)
    {
    }

    public function parse(string $source): PhpDocument
    {
        $this->sources[] = $source;

        return $this->parser->parse($source);
    }
}
