<?php

namespace Symfony\Lsp\Tests\Feature\Messenger;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\Messenger\MessengerBus;
use Symfony\Lsp\Feature\Messenger\MessengerCodeLensProvider;
use Symfony\Lsp\Feature\Messenger\MessengerCompletionProvider;
use Symfony\Lsp\Feature\Messenger\MessengerDiagnosticProvider;
use Symfony\Lsp\Feature\Messenger\MessengerExtractor;
use Symfony\Lsp\Feature\Messenger\MessengerHandler;
use Symfony\Lsp\Feature\Messenger\MessengerIndexRegistry;
use Symfony\Lsp\Feature\Messenger\MessengerMessage;
use Symfony\Lsp\Feature\Messenger\MessengerRelationshipProvider;
use Symfony\Lsp\Feature\Messenger\MessengerRelationshipResolver;
use Symfony\Lsp\Feature\Messenger\MessengerSourceIndexRegistry;
use Symfony\Lsp\Feature\Messenger\MessengerTransport;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class MessengerProviderTest extends TestCase
{
    public function testExtractsMessengerConfigurationSymbols(): void
    {
        $text = <<<'YAML'
framework:
  messenger:
    default_bus: command.bus
    failure_transport: failed
    buses:
      command.bus: ~
    transports:
      async: 'in-memory://'
      failed: 'in-memory://'
    routing:
      App\Message\Ping: [async]
YAML;
        $converter = new PositionConverter();
        $yamlParser = new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())));
        $extractor = new MessengerExtractor($converter, new TolerantPhpParser(new Parser()), $yamlParser, new PhpCommentParser());
        $facts = $extractor->extract('file:///workspace/config/packages/messenger.yaml', 'yaml', $text);

        $names = [];
        $declarations = [];
        foreach ($facts->symbols() as $symbol) {
            $names[] = $symbol->name();
            $declarations[] = $symbol->isDeclaration();
        }
        self::assertSame(['command.bus', 'async', 'failed', 'App\\Message\\Ping', 'async', 'command.bus', 'failed'], $names);
        self::assertSame([true, true, true, false, false, false, false], $declarations);
        $phpFacts = $extractor->extract('file:///workspace/src/Example.php', 'php', "<?php\nfoo(bus: 'not_messenger');\n\$dispatcher->dispatch(new NotAMessage());\n");
        self::assertSame([], $phpFacts->symbols());

        $handlerFacts = $extractor->extract(
            'file:///workspace/src/Handler.php',
            'php',
            <<<'PHP'
                <?php
                namespace App;

                use App\Message\Ping;
                use Symfony\Component\Messenger\Attribute\AsMessageHandler as HandlerAttribute;

                #[HandlerAttribute(bus: 'command.bus')]
                final class Handler
                {
                    #[HandlerAttribute(fromTransport: 'async', handles: Ping::class)]
                    public function __invoke(): void {}
                }
                PHP,
        );
        self::assertSame([
            "#[HandlerAttribute(bus: 'command.bus')]",
            "#[HandlerAttribute(fromTransport: 'async', handles: Ping::class)]",
        ], $handlerFacts->handlers());
        self::assertSame(['command.bus', 'async', 'App\Message\Ping'], array_map(static fn ($symbol): string => $symbol->name(), $handlerFacts->symbols()));
        self::assertFalse($handlerFacts->symbols()[0]->isDeclaration());

        $incompleteFacts = $extractor->extract('file:///workspace/src/IncompleteHandler.php', 'php', <<<'PHP'
            <?php
            use Symfony\Component\Messenger\Attribute\AsMessageHandler;

            #[AsMessageHandler(bus: 'command.bus')
            final class IncompleteHandler {}
            PHP);
        self::assertSame(["#[AsMessageHandler(bus: 'command.bus')"], $incompleteFacts->handlers());
        self::assertSame(['command.bus'], array_map(static fn ($symbol): string => $symbol->name(), $incompleteFacts->symbols()));
    }

    public function testScopesMessageBusParametersToTheirMethod(): void
    {
        $converter = new PositionConverter();
        $extractor = new MessengerExtractor(
            $converter,
            new TolerantPhpParser(new Parser()),
            new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
            new PhpCommentParser(),
        );
        $facts = $extractor->extract('file:///workspace/src/Dispatch.php', 'php', <<<'PHP'
            <?php
            namespace App;

            use Symfony\Component\Messenger\MessageBusInterface;

            final class Dispatch
            {
                public function message(MessageBusInterface $bus): void
                {
                    $bus->dispatch(new ExpectedMessage());
                }

                public function unrelated(object $bus): void
                {
                    $bus->dispatch(new IgnoredMessage());
                }
            }
            PHP);

        self::assertSame(['App\ExpectedMessage'], array_map(static fn ($symbol): string => $symbol->name(), $facts->symbols()));
    }

    public function testCompletesHoversNavigatesDiagnosesAndProvidesCodeLenses(): void
    {
        $yamlUri = 'file:///workspace/config/packages/messenger.yaml';
        $yaml = <<<'YAML'
framework:
  messenger:
    buses:
      command.bus: ~
    transports:
      async: 'in-memory://'
    routing:
      App\Message\Ping: async
services:
  handler:
    tags:
      - { name: messenger.message_handler, bus: command.bus }
      - { name: messenger.message_handler, bus: missing.bus, from_transport: async }
YAML;
        $messageUri = 'file:///workspace/src/Message/Ping.php';
        $message = "<?php\nnamespace App\\Message;\ninterface DomainEvent {}\nfinal class Ping implements DomainEvent {}\n";
        $handlerUri = 'file:///workspace/src/MessageHandler/PingHandler.php';
        $handler = "<?php\nnamespace App\\MessageHandler;\nfinal class PingHandler { public function __invoke(string \$message): void {} }\n";
        $controllerUri = 'file:///workspace/src/Controller/PingController.php';
        $controller = "<?php\nnamespace App\\Controller;\nuse App\\Message\\{Ping};\nuse Symfony\\Component\\Messenger\\{MessageBusInterface};\nfinal class PingController { public function __construct(private MessageBusInterface \$bus) {} public function send(): void { \$this->bus->dispatch(new Ping()); } }\n";
        $documents = new DocumentStore();
        $documents->open(new Document($yamlUri, 'yaml', 1, $yaml));
        $documents->open(new Document($messageUri, 'php', 1, $message));
        $documents->open(new Document($handlerUri, 'php', 1, $handler));
        $documents->open(new Document($controllerUri, 'php', 1, $controller));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $yamlParser = new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())));
        $extractor = new MessengerExtractor($converter, new TolerantPhpParser(new Parser()), $yamlParser, new PhpCommentParser());
        $indexes = new MessengerIndexRegistry();
        $indexes->forProject($project)->replace(
            [new MessengerBus('command.bus', true)],
            [new MessengerTransport('async', false)],
            [new MessengerMessage('App\\Message\\Ping', ['async'])],
            [new MessengerHandler('App\\Message\\DomainEvent', 'command.bus', 'handler', 'App\\MessageHandler\\PingHandler', '__invoke', 0, 'async')],
            true,
        );
        $sourceIndexes = new MessengerSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace($extractor->extract($yamlUri, 'yaml', $yaml), $extractor->extract($messageUri, 'php', $message), $extractor->extract($controllerUri, 'php', $controller));
        $classExtractor = new PhpClassDeclarationExtractor($converter, new TolerantPhpParser(new Parser()));
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $classIndexes->forProject($project)->replace(
            new DependencyInjectionSourceFacts($messageUri, classes: $classExtractor->extract($messageUri, $message)),
            new DependencyInjectionSourceFacts($handlerUri, classes: $classExtractor->extract($handlerUri, $handler)),
        );
        $documentResolver = new DocumentContextResolver($documents, $projects);
        $protocol = new LspProtocolMapper();
        $relationshipResolver = new MessengerRelationshipResolver($documentResolver, $converter, $protocol, $indexes, $sourceIndexes, $extractor, $classExtractor, $classIndexes);
        $completionProvider = new MessengerCompletionProvider($documentResolver, $converter, $protocol, $indexes, $yamlParser, new PhpCommentParser());
        $relationshipProvider = new MessengerRelationshipProvider($protocol, $indexes, $relationshipResolver);
        $diagnosticProvider = new MessengerDiagnosticProvider($documentResolver, $converter, $protocol, $indexes, $extractor, $classExtractor);
        $codeLensProvider = new MessengerCodeLensProvider($documentResolver, $protocol, $indexes, $classExtractor, $relationshipResolver);

        $completionParams = $this->params($yamlUri, $converter->toPosition($yaml, strpos($yaml, 'command.bus }') + 4));
        self::assertSame(['command.bus'], array_column($completionProvider->complete($completionParams) ?? [], 'label'));
        $routingCompletion = $this->params($yamlUri, $converter->toPosition($yaml, strpos($yaml, "async\nservices") + 3));
        self::assertSame(['async'], array_column($completionProvider->complete($routingCompletion) ?? [], 'label'));
        $hover = $relationshipProvider->hover($this->params($yamlUri, $converter->toPosition($yaml, strpos($yaml, 'async }') + 2)));
        self::assertStringContainsString('Messenger transport', json_encode($hover, \JSON_THROW_ON_ERROR));
        self::assertSame([$yamlUri], array_column($relationshipProvider->definition($this->params($yamlUri, $converter->toPosition($yaml, strpos($yaml, 'command.bus') + 2))) ?? [], 'uri'));
        self::assertSame(['messenger.unknown_bus'], array_column($diagnosticProvider->diagnostics(['textDocument' => ['uri' => $yamlUri]]) ?? [], 'code'));
        self::assertSame(['messenger.invalid_handler_signature'], array_column($diagnosticProvider->diagnostics(['textDocument' => ['uri' => $handlerUri]]) ?? [], 'code'));

        $messagePosition = $converter->toPosition($message, (int) strpos($message, 'Ping'));
        self::assertSame([$handlerUri], array_column($relationshipProvider->definition($this->params($messageUri, $messagePosition)) ?? [], 'uri'));
        self::assertContains($controllerUri, array_column($relationshipProvider->references($this->params($messageUri, $messagePosition)) ?? [], 'uri'));
        $dispatchPosition = $converter->toPosition($controller, (int) strrpos($controller, 'Ping'));
        self::assertSame([$messageUri, $handlerUri], array_column($relationshipProvider->definition($this->params($controllerUri, $dispatchPosition)) ?? [], 'uri'));
        $codeLens = $codeLensProvider->codeLenses(['textDocument' => ['uri' => $messageUri]])[0] ?? null;
        self::assertIsArray($codeLens);
        self::assertIsArray($codeLens['command'] ?? null);
        self::assertSame('1 Messenger handler', $codeLens['command']['title'] ?? null);
    }

    public function testIgnoresCommentedPhpMessengerConstructs(): void
    {
        $converter = new PositionConverter();
        $extractor = new MessengerExtractor(
            $converter,
            new TolerantPhpParser(new Parser()),
            new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
            new PhpCommentParser(),
        );
        $text = <<<'PHP'
            <?php
            namespace App;

            use Symfony\Component\Messenger\MessageBusInterface;

            final class Handler
            {
                public function __construct(private MessageBusInterface $bus) {}

                public function handle(): void
                {
                    // #[AsMessageHandler(bus: 'commented.bus', handles: CommentedMessage::class)]
                    // $this->bus->dispatch(new CommentedMessage());
                    // new Envelope(new CommentedMessage());
                    // new BusNameStamp('commented.bus');
                }
            }
            PHP;

        $facts = $extractor->extract('file:///workspace/src/Handler.php', 'php', $text);

        self::assertSame([], $facts->symbols());
        self::assertSame([], $facts->handlers());
    }

    public function testOffersNoMessengerCompletionsInsidePhpComments(): void
    {
        $uri = 'file:///workspace/src/Service.php';
        $text = "<?php // new BusNameStamp('comma";
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $indexes = new MessengerIndexRegistry();
        $indexes->forProject($project)->replace([new MessengerBus('command.bus', true)], [], [], [], true);
        $provider = new MessengerCompletionProvider(
            new DocumentContextResolver($documents, $projects),
            $converter,
            new LspProtocolMapper(),
            $indexes,
            new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
            new PhpCommentParser(),
        );
        $position = $converter->toPosition($text, \strlen($text));

        self::assertNull($provider->complete($this->params($uri, $position)));
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function params(string $uri, Position $position): array
    {
        return ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]];
    }
}
