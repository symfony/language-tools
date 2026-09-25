<?php

namespace Symfony\Lsp\Tests\Feature\Console;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Console\ConsoleCommandMetadata;
use Symfony\Lsp\Feature\Console\ConsoleDefinitionExtractor;
use Symfony\Lsp\Feature\Console\ConsoleExtractor;
use Symfony\Lsp\Feature\Console\ConsoleIndexRegistry;
use Symfony\Lsp\Feature\Console\ConsoleInvokableParameterExtractor;
use Symfony\Lsp\Feature\Console\ConsoleProvider;
use Symfony\Lsp\Feature\Console\ConsoleSourceIndexRegistry;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Tests\Support\LspRequests;

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
        [$provider] = $this->provider($uri, $text, new ConsoleCommandMetadata(
            'ReportCommand',
            '/workspace/src/Command/ReportCommand.php',
            ['command', 'report'],
            ['env', 'format', 'help', 'no-debug', 'verbose'],
            true,
        ));

        $diagnostics = $provider->diagnostics(LspRequests::document($uri));
        self::assertIsArray($diagnostics);
        self::assertSame(['console.unknown_argument', 'console.unknown_option'], array_column($diagnostics, 'code'));
        self::assertSame([
            'Unknown Console input argument "missing-argument".',
            'Unknown Console input option "missing-option".',
        ], array_column($diagnostics, 'message'));

        $completionText = str_replace("getOption('missing-option')", "getOption('f')", $text);
        [$completionProvider, $converter] = $this->provider($uri, $completionText, new ConsoleCommandMetadata(
            'ReportCommand',
            '/workspace/src/Command/ReportCommand.php',
            ['report'],
            ['format', 'help'],
            true,
        ));
        $cursor = strpos($completionText, "getOption('f") + \strlen("getOption('f");
        $items = $completionProvider->complete(LspRequests::offset($uri, $completionText, $cursor));
        self::assertSame(['format'], array_column($items ?? [], 'label'));

        [$sourceOnlyProvider, $sourceOnlyConverter] = $this->provider($uri, $completionText);
        $sourceOnlyItems = $sourceOnlyProvider->complete(LspRequests::offset($uri, $completionText, $cursor));
        self::assertSame(['format'], array_column($sourceOnlyItems ?? [], 'label'));
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

        [$extensible] = $this->provider($uri, $text, new ConsoleCommandMetadata('DynamicCommand', null, [], [], true));
        self::assertSame([], $extensible->diagnostics(LspRequests::document($uri)));

        [$incomplete] = $this->provider($uri, str_replace('$this->addOption($dynamicName);', '', $text), new ConsoleCommandMetadata('DynamicCommand', null, [], [], false));
        self::assertSame([], $incomplete->diagnostics(LspRequests::document($uri)));

        $staticText = str_replace('$this->addOption($dynamicName);', '', $text);
        [$missing] = $this->provider($uri, $staticText);
        self::assertSame([], $missing->diagnostics(LspRequests::document($uri)));

        [$incompleteSection] = $this->provider($uri, $staticText, new ConsoleCommandMetadata('DynamicCommand', null, [], [], true), false);
        self::assertSame([], $incompleteSection->diagnostics(LspRequests::document($uri)));
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
        [$provider, $converter] = $this->provider($uri, $text);
        $optionCursor = strpos($text, "getOption('d") + \strlen("getOption('d");
        $argumentCursor = strpos($text, "getArgument('s") + \strlen("getArgument('s");

        $options = $provider->complete(LspRequests::offset($uri, $text, $optionCursor));
        $arguments = $provider->complete(LspRequests::offset($uri, $text, $argumentCursor));

        self::assertSame(['dry-run'], array_column($options ?? [], 'label'));
        self::assertSame(['shared', 'source-path'], array_column($arguments ?? [], 'label'));
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
        [$provider, $converter] = $this->provider($uri, $text, new ConsoleCommandMetadata('EmptyCommand', null, [], [], true));
        $inputCursor = strpos($text, '$'."input->getArgument('z") + \strlen('$'."input->getArgument('z");
        $otherCursor = strpos($text, '$'."other->getArgument('z") + \strlen('$'."other->getArgument('z");

        self::assertSame([], $provider->complete(LspRequests::offset($uri, $text, $inputCursor)));
        self::assertNull($provider->complete(LspRequests::offset($uri, $text, $otherCursor)));
    }

    /** @return array{ConsoleProvider, PositionConverter} */
    private function provider(string $uri, string $text, ?ConsoleCommandMetadata $command = null, bool $runtimeComplete = true): array
    {
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $converter = new PositionConverter();
        $delimiters = new BalancedDelimiterMatcher();
        $extractor = new ConsoleExtractor(
            $converter,
            new TolerantPhpParser(new Parser()),
            new PhpCommentParser(),
            new ConsoleDefinitionExtractor(),
            new ConsoleInvokableParameterExtractor(),
        );
        $sourceIndexes = new ConsoleSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace($extractor->extract(new SourceDocument($uri, 'php', $text)));
        $indexes = new ConsoleIndexRegistry();
        $indexes->forProject($project)->replace(null === $command ? [] : [$command], $runtimeComplete);

        return [new ConsoleProvider(
            new DocumentContextResolver($documents, $projects),
            $converter,
            new LspProtocolMapper(),
            $indexes,
            $sourceIndexes,
            $extractor,
        ), $converter];
    }
}
