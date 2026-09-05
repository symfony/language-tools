<?php

namespace Symfony\Lsp\Tests\Feature\Console;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Console\ConsoleDefinitionExtractor;
use Symfony\Lsp\Feature\Console\ConsoleExtractor;
use Symfony\Lsp\Feature\Console\ConsoleInputKind;
use Symfony\Lsp\Feature\Console\ConsoleInputReceiverResolver;
use Symfony\Lsp\Feature\Console\ConsoleInvokableParameterExtractor;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\LastResultPhpParser;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpDocument;
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
                use SharedDefinition {
                    configure as sharedConfigure;
                }

                public function __invoke(
                    #[Argument] string $sourcePath,
                    #[Option(name: 'dry-run')] bool $dryRun,
                    #[Option('Description', 'output-format')] string $format,
                    #[\SensitiveParameter]
                    #[Option] bool $dryRunHTTP,
                    #[Deprecated, Argument] string $targetPath,
                    #[Option(description: 'Runs quietly')] bool $quietMode,
                ): int {
                    return 0;
                }
            }
            PHP;

        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Command/ReportCommand.php', 'php', $text));
        $declarations = [];
        foreach ($facts->declarations as $declaration) {
            $declarations[$declaration->className] = $declaration;
        }

        self::assertSame(['shared'], $declarations['App\Command\SharedDefinition']->arguments);
        self::assertSame(['format', 'report\name'], $declarations['App\Command\ReportCommand']->arguments);
        self::assertSame(['color', 'out"put'], $declarations['App\Command\ReportCommand']->options);
        self::assertSame(['App\Command\SharedDefinition'], $declarations['App\Command\ReportCommand']->traits);
        self::assertTrue($declarations['App\Command\ReportCommand']->complete);
        self::assertSame(['source-path', 'target-path'], $declarations['App\Command\ImportCommand']->arguments);
        self::assertSame(['dry-run', 'dry-run-http', 'output-format', 'quiet-mode'], $declarations['App\Command\ImportCommand']->options);
        self::assertSame(['App\Command\SharedDefinition'], $declarations['App\Command\ImportCommand']->traits);
        self::assertTrue($declarations['App\Command\ImportCommand']->complete);

        self::assertSame(['report\name', 'out"put'], array_map(static fn ($reference): string => $reference->name, $facts->references));
        self::assertSame([ConsoleInputKind::Argument, ConsoleInputKind::Option], array_map(static fn ($reference): ConsoleInputKind => $reference->kind, $facts->references));
    }

    public function testIndexesInputReferencesCapturedInsideClosures(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Command/CapturedCommand.php', 'php', <<<'PHP'
            <?php
            use Symfony\Component\Console\Input\InputInterface;

            final class CapturedCommand
            {
                public function execute(InputInterface $input): void
                {
                    $closure = function () use ($input): void {
                        $input->getArgument('closure');
                    };
                    $arrow = fn (): string => $input->getOption('arrow');
                    $nested = function () use ($input): void {
                        $arrow = fn (): string => $input->getOption('nested');
                    };
                    $uncaptured = function (): void {
                        $input->getOption('uncaptured');
                    };
                    $nestedUncaptured = function () use ($input): void {
                        $closure = function (): void {
                            $input->getOption('nested_uncaptured');
                        };
                    };
                    $shadowed = fn ($input): string => $input->getOption('shadowed');
                }
            }
            PHP));

        self::assertSame(['closure', 'arrow', 'nested'], array_map(static fn ($reference): string => $reference->name, $facts->references));
        self::assertSame([ConsoleInputKind::Argument, ConsoleInputKind::Option, ConsoleInputKind::Option], array_map(static fn ($reference): ConsoleInputKind => $reference->kind, $facts->references));
    }

    public function testScopesInputReferencesToTheirOwningMethods(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Command/ScopedCommand.php', 'php', <<<'PHP'
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
            PHP));

        self::assertSame(['tracked'], array_map(static fn ($reference): string => $reference->name, $facts->references));
        self::assertSame(['ScopedCommand'], array_map(static fn ($reference): string => $reference->commandClass, $facts->references));
    }

    public function testKeepsDeclarationsCallsAndAttributesWithTheirOwningTypes(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Command/Declarations.php', 'php', <<<'PHP'
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
            PHP));
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

    public function testIndexesConfigureCallsInsideClosuresConservatively(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Command/DeferredCommand.php', 'php', <<<'PHP'
            <?php
            final class DeferredCommand
            {
                protected function configure(): void
                {
                    $argument = function (): void {
                        $this->addArgument('closure');
                    };
                    $option = fn () => $this->addOption('arrow');
                }
            }
            PHP));

        self::assertSame(['closure'], $facts->declarations[0]->arguments);
        self::assertSame(['arrow'], $facts->declarations[0]->options);
        self::assertFalse($facts->declarations[0]->complete);
    }

    public function testScopesConfigureBodiesAroundBracesInStrings(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Command/HelpCommand.php', 'php', <<<'PHP'
            <?php
            final class HelpCommand
            {
                protected function configure(): void
                {
                    $this->setHelp(<<<'HELP'
                        Close the block with a } brace.
                        HELP);
                    $deferred = function (): void {
                        $this->addArgument('deferred');
                    };
                }
            }
            PHP));

        self::assertSame(['deferred'], $facts->declarations[0]->arguments);
        self::assertFalse($facts->declarations[0]->complete);
    }

    public function testMarksDynamicDefinitionsIncomplete(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Command/DynamicCommand.php', 'php', <<<'PHP'
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

            #[AsCommand]
            final class SpreadInvokableCommand
            {
                public function __invoke(#[Option(...$definition)] bool $enabled = false): int
                {
                    return 0;
                }
            }
            PHP));

        self::assertFalse($facts->declarations[0]->complete);
        self::assertFalse($facts->declarations[1]->complete);
        self::assertSame([], $facts->declarations[2]->options);
        self::assertFalse($facts->declarations[2]->complete);
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
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Command/DraftCommand.php', 'php', <<<'PHP'
            <?php
            final class DraftCommand
            {
                protected function configure(): void
                {
                    $this->addArgument('draft');
            PHP));

        self::assertFalse($facts->declarations[0]->complete);
    }

    public function testKeepsInvokableParameterNamesFromIncompleteSource(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Command/DraftCommand.php', 'php', <<<'PHP'
            <?php
            use Symfony\Component\Console\Attribute\AsCommand;
            use Symfony\Component\Console\Attribute\Option;

            #[AsCommand]
            final class DraftCommand
            {
                public function __invoke(
                    #[Option] bool $dryRun,
            PHP));

        self::assertSame(['dry-run'], $facts->declarations[0]->options);
    }

    public function testReadsCommentedDefinitionsFromASingleDocumentParse(): void
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
                        // Comments cannot hide names, not even buildDefinition().
                        new InputArgument('report'),
                    ]);
                }
            }
            PHP;
        $inner = new CountingConsolePhpParser(new TolerantPhpParser(new Parser()));
        $parser = new LastResultPhpParser($inner);
        $delimiters = new BalancedDelimiterMatcher();
        $extractor = new ConsoleExtractor(
            new PositionConverter(),
            $parser,
            new PhpCommentParser(),
            new ConsoleDefinitionExtractor(),
            new ConsoleInvokableParameterExtractor(),
            new ConsoleInputReceiverResolver(),
        );

        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Command/ReportCommand.php', 'php', $text));
        $parser->parse($text);

        self::assertSame(['report'], $facts->declarations[0]->arguments);
        self::assertTrue($facts->declarations[0]->complete);
        self::assertSame([$text], $inner->sources);
    }

    public function testReadsDefinitionsOnlyFromSymfonyInputClasses(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Command/ImportCommand.php', 'php', <<<'PHP'
            <?php
            namespace App\Command;

            use App\Input\InputArgument;
            use App\Input\InputOption;
            use Symfony\Component\Console\Input\InputArgument as Argument;

            final class ImportCommand
            {
                protected function configure(): void
                {
                    $this->setDefinition([
                        new InputArgument('unrelated'),
                        new InputOption('unrelated'),
                        new Argument('aliased'),
                        new \Symfony\Component\Console\Input\InputOption('qualified'),
                    ]);
                }
            }
            PHP));

        self::assertSame(['aliased'], $facts->declarations[0]->arguments);
        self::assertSame(['qualified'], $facts->declarations[0]->options);
        self::assertFalse($facts->declarations[0]->complete);
    }

    public function testMarksDefinitionListsWithHiddenNamesIncomplete(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Command/HiddenCommand.php', 'php', <<<'PHP'
            <?php
            namespace App\Command;

            use Symfony\Component\Console\Input\InputArgument;
            use Symfony\Component\Console\Input\InputDefinition;

            final class HiddenCommand
            {
                protected function configure(): void
                {
                    $this->setDefinition(new InputDefinition([
                        new InputArgument('kept'),
                        ...$this->extraArguments(),
                    ]));
                }
            }
            PHP));

        self::assertSame(['kept'], $facts->declarations[0]->arguments);
        self::assertFalse($facts->declarations[0]->complete);
    }

    public function testKeepsDefinitionsWrittenWithComputedConstructorArguments(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Command/DefaultsCommand.php', 'php', <<<'PHP'
            <?php
            namespace App\Command;

            use Symfony\Component\Console\Input\InputArgument;
            use Symfony\Component\Console\Input\InputDefinition;

            final class DefaultsCommand
            {
                protected function configure(): void
                {
                    $this->setDefinition(new InputDefinition([
                        new InputArgument('format', InputArgument::OPTIONAL, 'The format', $this->defaultFormat()),
                    ]));
                }
            }
            PHP));

        self::assertSame(['format'], $facts->declarations[0]->arguments);
        self::assertTrue($facts->declarations[0]->complete);
    }

    public function testMarksDefinitionsIncompleteWhileTheListIsBeingTyped(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Command/TypingCommand.php', 'php', <<<'PHP'
            <?php
            namespace App\Command;

            use Symfony\Component\Console\Input\InputArgument;

            final class TypingCommand
            {
                protected function configure(): void
                {
                    $this->setDefinition([new InputArgument('format'),
                }
            }
            PHP));

        self::assertFalse($facts->declarations[0]->complete);
    }

    private function extractor(): ConsoleExtractor
    {
        $delimiters = new BalancedDelimiterMatcher();

        return new ConsoleExtractor(
            new PositionConverter(),
            new TolerantPhpParser(new Parser()),
            new PhpCommentParser(),
            new ConsoleDefinitionExtractor(),
            new ConsoleInvokableParameterExtractor(),
            new ConsoleInputReceiverResolver(),
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
