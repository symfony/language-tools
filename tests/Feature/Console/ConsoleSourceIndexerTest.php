<?php

namespace Symfony\Lsp\Tests\Feature\Console;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Console\ConsoleExtractor;
use Symfony\Lsp\Feature\Console\ConsoleSourceFacts;
use Symfony\Lsp\Feature\Console\ConsoleSourceIndexer;
use Symfony\Lsp\Feature\Console\ConsoleSourceIndexRegistry;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpExpressionParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Project\Project;

final class ConsoleSourceIndexerTest extends TestCase
{
    public function testRestoresPersistedConsoleFacts(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $uri = 'file:///workspace/src/Command/ReportCommand.php';
        $document = new SourceDocument($uri, 'php', <<<'PHP'
            <?php
            use Symfony\Component\Console\Command\Command;
            use Symfony\Component\Console\Input\InputInterface;
            final class ReportCommand extends Command
            {
                protected function configure(): void
                {
                    $this->addArgument('report');
                }

                protected function execute(InputInterface $input): int
                {
                    $input->getArgument('report');
                    return 0;
                }
            }
            PHP);
        $indexes = new ConsoleSourceIndexRegistry();
        $indexer = $this->indexer($indexes);
        $indexer->begin($project);
        $facts = $indexer->index($project, $document);
        self::assertInstanceOf(ConsoleSourceFacts::class, $facts);
        $indexer->finish($project);

        $codec = new SourceIndexPayloadCodec();
        $codec->validate([$indexer]);
        $payload = $codec->encode($indexer->name(), $facts);
        $restoredIndexes = new ConsoleSourceIndexRegistry();
        $restored = $this->indexer($restoredIndexes);
        $restored->begin($project);
        $restored->restore($project, $codec->decode($indexer->name(), $payload));
        $restored->finish($project);

        $definition = $restoredIndexes->forProject($project)->definition('ReportCommand');
        self::assertSame(['report'], $definition->arguments);
        self::assertTrue($definition->complete);
    }

    private function indexer(ConsoleSourceIndexRegistry $indexes): ConsoleSourceIndexer
    {
        $converter = new PositionConverter();

        return new ConsoleSourceIndexer($indexes, new ConsoleExtractor(
            $converter,
            new TolerantPhpParser(new Parser()),
            new PhpExpressionParser(new TolerantPhpParser(new Parser())),
            new PhpCommentParser(),
            new BalancedDelimiterMatcher(),
        ));
    }
}
