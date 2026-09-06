<?php

namespace Symfony\Lsp\Tests\Feature\Messenger;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Feature\Messenger\MessengerExtractor;
use Symfony\Lsp\Feature\Messenger\MessengerSourceFacts;
use Symfony\Lsp\Feature\Messenger\MessengerSourceIndexer;
use Symfony\Lsp\Feature\Messenger\MessengerSourceIndexRegistry;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Index\SourceIndexProviderPipeline;
use Symfony\Lsp\Index\SourceParseHealth;
use Symfony\Lsp\Parser\CommentParserRegistry;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;

final class MessengerSourceIndexerTest extends TestCase
{
    public function testStoresNoPayloadForPhpMethodsWithoutMessengerSourceFacts(): void
    {
        $uri = 'file:///workspace/src/Utility.php';
        $indexes = new MessengerSourceIndexRegistry();
        $indexer = new MessengerSourceIndexer($indexes, $this->extractor());
        $pipeline = new SourceIndexProviderPipeline(new SourceIndexPayloadCodec(), [$indexer]);
        $project = new Project('/workspace', 'file:///workspace');
        $pipeline->begin($project);

        $payloads = $pipeline->index($project, new SourceDocument($uri, 'php', <<<'PHP'
            <?php
            namespace App;

            final class Utility
            {
                protected function normalize(string $value): void {}

                private function validate(array $value): void {}

                public function transform(string $value): void {}
            }
            PHP));
        $pipeline->finish($project);

        self::assertSame('', $payloads['messenger']);
        self::assertTrue($indexes->forProject($project)->factsForUri($uri)?->isEmpty());
    }

    public function testKeepsRuntimeRelevantRelationshipsAndHandlerAttributes(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Handlers.php', 'php', <<<'PHP'
            <?php
            namespace App;

            use Symfony\Component\Messenger\Attribute\AsMessageHandler;

            interface MessageContract
            {
            }

            final class ConfiguredHandler implements MessageContract
            {
                public function handle(string|int $message): void {}

                protected function protectedHandler(string $message): void {}

                private function privateHandler(array $message): void {}
            }

            #[AsMessageHandler(handles: FirstMessage::class)]
            final class AttributedHandler
            {
                public function __invoke(string $message): void {}
            }

            final class MethodHandler
            {
                #[AsMessageHandler(handles: SecondMessage::class)]
                public function process(callable $message): void {}
            }
            PHP));

        self::assertSame([
            'App\\ConfiguredHandler' => ['App\\MessageContract'],
        ], $facts->parents);
        self::assertSame([
            '#[AsMessageHandler(handles: FirstMessage::class)]',
            '#[AsMessageHandler(handles: SecondMessage::class)]',
        ], $facts->handlers);
        self::assertSame(['App\\FirstMessage', 'App\\SecondMessage'], array_map(static fn ($symbol): string => $symbol->name, $facts->symbols));
    }

    public function testPreservesRuntimeRelevantFactsAcrossIncompleteOverlays(): void
    {
        $uri = 'file:///workspace/src/Handler.php';
        $project = new Project('/workspace', 'file:///workspace');
        $indexes = new MessengerSourceIndexRegistry();
        $indexer = new MessengerSourceIndexer($indexes, $this->extractor());
        $indexer->overlay($project, new Document($uri, 'php', 1, <<<'PHP'
            <?php
            namespace App;

            use Symfony\Component\Messenger\Attribute\AsMessageHandler;

            #[AsMessageHandler]
            final class Handler extends BaseHandler
            {
                public function __invoke(string $message): void {}
            }
            PHP), SourceParseHealth::Healthy);
        $indexer->overlay($project, new Document($uri, 'php', 2, '<?php namespace App; final class Handler'), SourceParseHealth::Partial);

        $facts = $indexes->forProject($project)->factsForUri($uri);
        self::assertInstanceOf(MessengerSourceFacts::class, $facts);
        self::assertSame(['App\\Handler' => ['App\\BaseHandler']], $facts->parents);
        self::assertSame(['#[AsMessageHandler]'], $facts->handlers);
        self::assertSame([$facts->parents, $facts->handlers], $indexer->runtimeDeclarations($facts));
    }

    private function extractor(): MessengerExtractor
    {
        $converter = new PositionConverter();

        return new MessengerExtractor(
            $converter,
            new TolerantPhpParser(new Parser()),
            new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
            new CommentParserRegistry(['php' => new PhpCommentParser()]),
        );
    }
}
