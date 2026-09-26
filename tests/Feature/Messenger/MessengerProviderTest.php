<?php

namespace Symfony\Lsp\Tests\Feature\Messenger;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\Messenger\MessengerBus;
use Symfony\Lsp\Feature\Messenger\MessengerCodeLensProvider;
use Symfony\Lsp\Feature\Messenger\MessengerCompletionProvider;
use Symfony\Lsp\Feature\Messenger\MessengerDiagnosticProvider;
use Symfony\Lsp\Feature\Messenger\MessengerExtractor;
use Symfony\Lsp\Feature\Messenger\MessengerHandlerDeclaration;
use Symfony\Lsp\Feature\Messenger\MessengerIndexRegistry;
use Symfony\Lsp\Feature\Messenger\MessengerRelationshipProvider;
use Symfony\Lsp\Feature\Messenger\MessengerSourceIndexRegistry;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\CommentParserRegistry;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\AnalysisSettingsRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectAnalysisSettings;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Tests\Support\EnvironmentScopes;
use Symfony\Lsp\Tests\Support\LspRequests;
use Symfony\Lsp\Tests\Support\ProjectTestKit;
use Symfony\Lsp\Tests\Support\ProviderRequests;

final class MessengerProviderTest extends TestCase
{
    public function testExtractsMessengerConfigurationSymbols(): void
    {
        $text = <<<'YAML'
framework:
  messenger:
    default_bus: command.bus
    # failure_transport: failed
    failure_transport: failed
    buses:
      command.bus: ~
    transports:
      async: 'in-memory://'
      failed: 'in-memory://'
    routing:
      App\Message\Ping: [async]
YAML;
        $extractor = $this->extractor();
        $facts = $extractor->extract(new SourceDocument('file:///workspace/config/packages/messenger.yaml', 'yaml', $text));

        $names = [];
        $declarations = [];
        foreach ($facts->symbols as $symbol) {
            $names[] = $symbol->name;
            $declarations[] = $symbol->declaration;
        }
        self::assertSame(['command.bus', 'failed', 'command.bus', 'async', 'failed', 'App\\Message\\Ping', 'async'], $names);
        self::assertSame([false, false, true, true, true, false, false], $declarations);
        $phpFacts = $extractor->extract(new SourceDocument('file:///workspace/src/Example.php', 'php', "<?php\nfoo(bus: 'not_messenger');\n\$dispatcher->dispatch(new NotAMessage());\n"));
        self::assertSame([], $phpFacts->symbols);

        $handlerFacts = $extractor->extract(
            new SourceDocument('file:///workspace/src/Handler.php',
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
                PHP),
        );
        self::assertSame([
            "#[HandlerAttribute(bus: 'command.bus')]",
            "#[HandlerAttribute(fromTransport: 'async', handles: Ping::class)]",
        ], $handlerFacts->handlers);
        self::assertSame(['command.bus', 'async', 'App\Message\Ping'], array_map(static fn ($symbol): string => $symbol->name, $handlerFacts->symbols));
        self::assertFalse($handlerFacts->symbols[0]->declaration);

        $incompleteFacts = $extractor->extract(new SourceDocument('file:///workspace/src/IncompleteHandler.php', 'php', <<<'PHP'
            <?php
            use Symfony\Component\Messenger\Attribute\AsMessageHandler;

            #[AsMessageHandler(bus: 'command.bus')
            final class IncompleteHandler {}
            PHP));
        self::assertSame(["#[AsMessageHandler(bus: 'command.bus')"], $incompleteFacts->handlers);
        self::assertSame(['command.bus'], array_map(static fn ($symbol): string => $symbol->name, $incompleteFacts->symbols));
    }

    public function testExtractsBusAndTransportReferencesOnlyFromMessengerConfigurationKeys(): void
    {
        $text = <<<'YAML'
            framework:
                messenger:
                    default_bus: command.bus
                    buses:
                        command.bus:
                            middleware:
                                - validation
                    transports:
                        async:
                            dsn: 'in-memory://'
                            failure_transport: failed
            services:
                monolog_mailer:
                    class: Symfony\Component\Mailer\Mailer
                    arguments:
                        $bus: null # Send e-mail synchronously
                        $failure_transport: ~
                App\Handler:
                    tags:
                        - { name: messenger.message_handler, bus: 'quoted.bus', from_transport: async }
                        - messenger.message_handler: { bus: map.bus }
                        - { name: messenger.message_handler, bus: '%env(BUS)%' }
            YAML;
        $extractor = $this->extractor();
        $facts = $extractor->extract(new SourceDocument('file:///workspace/config/services.yaml', 'yaml', $text));

        $references = [];
        $lines = explode("\n", $text);
        foreach ($facts->symbols as $symbol) {
            if ($symbol->declaration) {
                continue;
            }
            $references[] = [
                $symbol->kind->name,
                $symbol->name,
                substr($lines[$symbol->range->start->line], $symbol->range->start->character, $symbol->range->end->character - $symbol->range->start->character),
            ];
        }
        self::assertSame([
            ['Bus', 'command.bus', 'command.bus'],
            ['Transport', 'failed', 'failed'],
            ['Bus', 'quoted.bus', 'quoted.bus'],
            ['Transport', 'async', 'async'],
            ['Bus', 'map.bus', 'map.bus'],
        ], $references);
    }

    public function testExtractsRoutedTransportsFromEverySenderNotation(): void
    {
        $text = <<<'YAML'
            framework:
                messenger:
                    routing:
                        'App\Message\Plain': async
                        'App\Message\List': [async, audit]
                        'App\Message\Flow': { senders: [async] }
                        'App\Message\Block':
                            senders: [async]
                            send_and_handle: true
            YAML;
        $extractor = $this->extractor();
        $facts = $extractor->extract(new SourceDocument('file:///workspace/config/packages/messenger.yaml', 'yaml', $text));

        $symbols = [];
        foreach ($facts->symbols as $symbol) {
            $symbols[] = [$symbol->kind->name, $symbol->name];
        }
        self::assertSame([
            ['Message', 'App\Message\Plain'],
            ['Transport', 'async'],
            ['Message', 'App\Message\List'],
            ['Transport', 'async'],
            ['Transport', 'audit'],
            ['Message', 'App\Message\Flow'],
            ['Transport', 'async'],
            ['Message', 'App\Message\Block'],
            ['Transport', 'async'],
        ], $symbols);
    }

    public function testIndexesOnlyCompleteClassReferencesInHandlerMessages(): void
    {
        $extractor = $this->extractor();
        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Handler.php', 'php', <<<'PHP'
            <?php
            namespace App;

            use App\Message\Ping;
            use Symfony\Component\Messenger\Attribute\AsMessageHandler;

            #[AsMessageHandler(handles: Ping /* message */ ::class)]
            #[AsMessageHandler(handles: (IgnoredParenthesizedMessage::class))]
            final class Handler
            {
            }
            PHP));

        self::assertSame(['App\Message\Ping'], array_map(static fn ($symbol): string => $symbol->name, $facts->symbols));
    }

    public function testIgnoresClassReferencesEmbeddedInHandlerExpressions(): void
    {
        $extractor = $this->extractor();
        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Handler.php', 'php', <<<'PHP'
            <?php
            namespace App;

            use Symfony\Component\Messenger\Attribute\AsMessageHandler;

            #[AsMessageHandler(handles: MESSAGE_PREFIX . IgnoredPrefixMessage::class)]
            #[AsMessageHandler(handles: IgnoredSuffixMessage::class . MESSAGE_SUFFIX)]
            final class Handler
            {
            }
            PHP));

        self::assertSame([], $facts->symbols);
    }

    public function testPreservesGroupedRepeatableHandlerAttributes(): void
    {
        $extractor = $this->extractor();
        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Handler.php', 'php', <<<'PHP'
            <?php
            namespace App;

            use App\Message\First;
            use App\Message\Second;
            use Symfony\Component\Messenger\Attribute\AsMessageHandler;

            #[AsMessageHandler(handles: First::class), AsMessageHandler(handles: Second::class)]
            final class Handler
            {
            }
            PHP));

        self::assertSame(['App\Message\First', 'App\Message\Second'], array_map(static fn ($symbol): string => $symbol->name, $facts->symbols));
        self::assertCount(2, $facts->handlers);
    }

    public function testExtractsNamedMessageInheritanceInSourceOrder(): void
    {
        $extractor = $this->extractor();
        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Message.php', 'php', <<<'PHP'
            <?php
            namespace App\Message;

            use Vendor\Contracts\{ExternalMessage, Traceable as TraceableMessage};

            interface ParentMessage
            {
            }

            interface Inner
            {
            }

            interface ChildContract extends ParentMessage, ExternalMessage
            {
            }

            abstract class BaseMessage implements TraceableMessage
            {
            }

            final class ChildMessage extends BaseMessage implements ChildContract, Inner
            {
                public function anonymous(): object
                {
                    return new class implements Inner {
                    };
                }
            }

            enum Status implements ChildContract, TraceableMessage
            {
                case Ready;
            }

            trait MessageTrait
            {
            }
            PHP));

        self::assertSame([
            'App\\Message\\ChildContract' => ['App\\Message\\ParentMessage', 'Vendor\\Contracts\\ExternalMessage'],
            'App\\Message\\BaseMessage' => ['Vendor\\Contracts\\Traceable'],
            'App\\Message\\ChildMessage' => ['App\\Message\\BaseMessage', 'App\\Message\\ChildContract', 'App\\Message\\Inner'],
            'App\\Message\\Status' => ['App\\Message\\ChildContract', 'Vendor\\Contracts\\Traceable'],
        ], $facts->parents);
    }

    public function testScopesMessageBusParametersToTheirMethod(): void
    {
        $extractor = $this->extractor();
        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Dispatch.php', 'php', <<<'PHP'
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
            PHP));

        self::assertSame(['App\ExpectedMessage'], array_map(static fn ($symbol): string => $symbol->name, $facts->symbols));
    }

    public function testIndexesMessageReferencesCapturedInsideClosures(): void
    {
        $extractor = $this->extractor();
        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Dispatch.php', 'php', <<<'PHP'
            <?php
            namespace App;

            use Symfony\Component\Messenger\MessageBusInterface;

            final class Dispatch
            {
                public function dispatch(MessageBusInterface $bus): void
                {
                    $closure = function () use ($bus): void {
                        $bus->dispatch(new ClosureMessage());
                    };
                    $arrow = fn () => $bus->dispatch(new ArrowMessage());
                    $uncaptured = function (): void {
                        $bus->dispatch(new UncapturedMessage());
                    };
                    $shadowed = fn ($bus) => $bus->dispatch(new ShadowedMessage());
                }
            }
            PHP));

        self::assertSame(['App\ClosureMessage', 'App\ArrowMessage'], array_map(static fn ($symbol): string => $symbol->name, $facts->symbols));
    }

    public function testIndexesOnlyDirectPositionalMessageCreations(): void
    {
        $kit = new ProjectTestKit();
        $converter = $kit->get(PositionConverter::class);
        $extractor = $kit->get(MessengerExtractor::class);
        $text = <<<'PHP'
            <?php
            namespace App;

            use App\Message\Ping as AliasedMessage;
            use Symfony\Component\Messenger\Envelope as MessageEnvelope;
            use Symfony\Component\Messenger\MessageBusInterface;

            final class Dispatch
            {
                public function __construct(private MessageBusInterface $bus) {}

                public function dispatch(): void
                {
                    $this->bus->dispatch(new AliasedMessage());
                    $this->bus->dispatch(new \App\Message\QualifiedMessage());
                    $this->bus->dispatch(unrelated: new IgnoredMessage());
                    $this->bus->dispatch(...[new SpreadMessage()]);
                    $this->bus->dispatch(factory(new NestedMessage()));
                    new MessageEnvelope(new AliasedEnvelopeMessage());
                    new \Symfony\Component\Messenger\Envelope(new \App\Message\QualifiedEnvelopeMessage());
                    new MessageEnvelope(unrelated: new IgnoredEnvelopeMessage());
                    new MessageEnvelope(...[new SpreadEnvelopeMessage()]);
                    new MessageEnvelope(factory(new NestedEnvelopeMessage()));
                }
            }
            PHP;

        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Dispatch.php', 'php', $text));
        $ranges = array_map(static function ($symbol) use ($converter, $text): string {
            $start = $converter->toByteOffset($text, $symbol->range->start);
            $end = $converter->toByteOffset($text, $symbol->range->end);

            return substr($text, $start, $end - $start);
        }, $facts->symbols);

        self::assertSame([
            'App\Message\Ping',
            'App\Message\QualifiedMessage',
            'App\AliasedEnvelopeMessage',
            'App\Message\QualifiedEnvelopeMessage',
        ], array_map(static fn ($symbol): string => $symbol->name, $facts->symbols));
        self::assertSame([
            'AliasedMessage',
            '\\App\Message\QualifiedMessage',
            'AliasedEnvelopeMessage',
            '\\App\Message\QualifiedEnvelopeMessage',
        ], $ranges);
    }

    public function testCompletesHoversNavigatesDiagnosesAndProvidesCodeLenses(): void
    {
        $yamlUri = 'file:///workspace/config/packages/messenger.yaml';
        $yaml = <<<'YAML'
framework:
  messenger:
    # failure_transport: failed
    buses:
      command.bus: ~
    transports:
      async: 'in-memory://'
    routing:
      App\Message\Ping: async
services:
  handler:
    arguments:
      $bus: null
    tags:
      - { name: messenger.message_handler, bus: command.bus }
      - { name: messenger.message_handler, bus: missing.bus, from_transport: async }
YAML;
        $messageUri = 'file:///workspace/src/Message/Ping.php';
        $message = "<?php\nnamespace App\\Message;\ninterface DomainEvent {}\ninterface IncompleteDeclaration\ninterface MessageContract extends DomainEvent {}\nfinal class Ping implements MessageContract {}\n";
        $handlerUri = 'file:///workspace/src/MessageHandler/PingHandler.php';
        $handler = "<?php\nnamespace App\\MessageHandler;\nuse App\\Message\\Ping;\nfinal class UnrelatedHandler { public function __invoke(string \$message): void {} }\nfinal class PingHandler { public function __invoke(Ping \$message): void {} }\nfinal class NullableStringHandler { public function handle(string|null \$message): void {} }\nfinal class ScalarUnionHandler { public function handle(string|int \$message): void {} }\n";
        $controllerUri = 'file:///workspace/src/Controller/PingController.php';
        $controller = "<?php\nnamespace App\\Controller;\nuse App\\Message\\{Ping};\nuse Symfony\\Component\\Messenger\\{MessageBusInterface};\nfinal class PingController { public function __construct(private MessageBusInterface \$bus) {} public function send(): void { \$this->bus->dispatch(new Ping()); } }\n";
        $kit = (new ProjectTestKit())
            ->open($yamlUri, $yaml)
            ->open($messageUri, $message)
            ->open($handlerUri, $handler)
            ->open($controllerUri, $controller)
            ->index()
            ->runtime('messenger', [
                'buses' => [['name' => 'command.bus', 'default' => true]],
                'transports' => [['name' => 'async', 'failure' => false]],
                'messages' => [['class' => 'App\\Message\\Ping', 'transports' => ['async']]],
                'handlers' => [
                    ['message' => 'App\\Message\\DomainEvent', 'bus' => 'command.bus', 'service' => 'handler', 'class' => 'App\\MessageHandler\\PingHandler', 'method' => '__invoke', 'fromTransport' => 'async'],
                    ['message' => 'App\\Message\\Other', 'bus' => 'command.bus', 'service' => 'nullable_string_handler', 'class' => 'App\\MessageHandler\\NullableStringHandler', 'method' => 'handle', 'fromTransport' => 'async'],
                    ['message' => 'App\\Message\\Other', 'bus' => 'command.bus', 'service' => 'scalar_union_handler', 'class' => 'App\\MessageHandler\\ScalarUnionHandler', 'method' => 'handle', 'fromTransport' => 'async'],
                ],
                'complete' => true,
            ])
        ;
        $completionProvider = $kit->get(MessengerCompletionProvider::class);
        $relationshipProvider = $kit->get(MessengerRelationshipProvider::class);
        $diagnosticProvider = $kit->get(MessengerDiagnosticProvider::class);

        self::assertSame(['command.bus'], $kit->labels($completionProvider->complete($kit->positioned($kit->after($yamlUri, 'bus: comm')))));
        self::assertSame([], $completionProvider->complete($kit->positioned($kit->after($yamlUri, '# failure_transport: fa'))));
        self::assertSame([], $completionProvider->complete($kit->positioned($kit->after($yamlUri, '$bus: nul'))));
        $bundleUri = 'file:///workspace/config/packages/other_bundle.yaml';
        $bundleYaml = "other_bundle:\n    bus: com";
        $kit->open($bundleUri, $bundleYaml);
        self::assertSame([], $completionProvider->complete($kit->positioned($kit->offset($bundleUri, \strlen($bundleYaml)))));
        self::assertSame(['async'], $kit->labels($completionProvider->complete($kit->positioned($kit->after($yamlUri, 'Ping: asy')))));
        self::assertStringContainsString('Messenger transport', $kit->hoverText($relationshipProvider->hover($kit->positioned($kit->after($yamlUri, 'from_transport: as')))));
        self::assertSame([$yamlUri], $kit->targets($relationshipProvider->definition($kit->positioned($kit->inside($yamlUri, 'command.bus')))));
        self::assertSame(['messenger.unknown_bus'], $kit->codes($diagnosticProvider->diagnostics($kit->document($yamlUri))));
        self::assertSame(['messenger.invalid_handler_signature', 'messenger.invalid_handler_signature'], $kit->codes($diagnosticProvider->diagnostics($kit->document($handlerUri))));

        $declared = $kit->at($messageUri, 'Ping');
        self::assertSame([$handlerUri], $kit->targets($relationshipProvider->definition($kit->positioned($declared))));
        self::assertContains($controllerUri, $kit->targets($relationshipProvider->references($kit->references($declared))));
        $dispatched = $kit->at($controllerUri, 'Ping());');
        self::assertSame([$messageUri, $handlerUri], $kit->targets($relationshipProvider->definition($kit->positioned($dispatched))));
        self::assertSame(
            ['1 Messenger handler', '1 Messenger handler', '1 Messenger handler'],
            $kit->titles($kit->get(MessengerCodeLensProvider::class)->codeLenses($kit->document($messageUri))),
            'Every message the handler accepts, including the contracts it inherits from, announces it.',
        );
    }

    public function testRelatesMessageReferencesWrittenWithAnotherCaseOrALeadingBackslash(): void
    {
        $messageUri = 'file:///workspace/src/Message/Foo.php';
        $controllerUri = 'file:///workspace/src/Controller/FooController.php';
        $kit = (new ProjectTestKit())
            ->open($messageUri, "<?php\nnamespace App\\Message;\nfinal class Foo {}\n")
            ->open($controllerUri, "<?php\nnamespace App\\Controller;\nuse Symfony\\Component\\Messenger\\MessageBusInterface;\nfunction send(MessageBusInterface \$bus): void { \$bus->dispatch(new \\app\\message\\FOO()); }\n")
            ->index()
            ->runtime('messenger', [
                'buses' => [['name' => 'command.bus', 'default' => true]],
                'transports' => [],
                'messages' => [['class' => 'App\\Message\\Foo', 'transports' => []]],
                'handlers' => [],
                'complete' => true,
            ])
        ;

        self::assertContains($controllerUri, $kit->targets($kit->get(MessengerRelationshipProvider::class)->references($kit->references($kit->at($messageUri, 'Foo')))));
    }

    #[DataProvider('handlerSignatureDocumentProvider')]
    public function testDiagnosesRuntimeHandlerSignaturesFromCurrentDocument(string $indexedType, string $currentType, bool $invalid): void
    {
        $uri = 'file:///workspace/src/Handler.php';
        $indexedText = self::handlerSource($indexedType);
        $currentText = self::handlerSource($currentType);
        $kit = (new ProjectTestKit())
            ->open($uri, $currentText, version: 2)
            ->index([$uri => $indexedText])
            ->runtime('messenger', [
                'handlers' => [['message' => 'App\\Message', 'bus' => 'messenger.bus.default', 'service' => 'handler', 'class' => 'App\\Handler', 'method' => '__invoke']],
                'complete' => true,
            ])
        ;

        $diagnostics = $kit->get(MessengerDiagnosticProvider::class)->diagnostics($kit->document($uri));
        if (!$invalid) {
            self::assertSame([], $diagnostics);

            return;
        }
        $converter = $kit->get(PositionConverter::class);
        $parameterOffset = strpos($currentText, '$message');
        self::assertIsInt($parameterOffset);
        self::assertSame([[
            'range' => $kit->get(LspProtocolMapper::class)->range(new Range(
                $converter->toPosition($currentText, $parameterOffset + 1),
                $converter->toPosition($currentText, $parameterOffset + \strlen('$message')),
            )),
            'severity' => 1,
            'source' => 'symfony',
            'code' => 'messenger.invalid_handler_signature',
            'message' => 'Messenger handler "App\\Handler::__invoke" cannot accept message "App\\Message".',
        ]], $diagnostics);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function handlerSignatureDocumentProvider(): iterable
    {
        yield 'saved valid signature' => ['\\stdClass', '\\stdClass', false];
        yield 'saved invalid signature' => ['string', 'string', true];
        yield 'open valid signature replaces saved invalid signature' => ['string', '\\stdClass', false];
        yield 'open invalid signature replaces saved valid signature' => ['\\stdClass', 'string', true];
    }

    /** @param list<string> $expectedCodes */
    #[DataProvider('environmentScopedTransportProvider')]
    public function testDiagnosesTransportsOnlyInTheEnvironmentSectionsThatLoadThem(string $uri, string $yaml, string $environment, array $expectedCodes): void
    {
        $kit = (new ProjectTestKit())
            ->open($uri, $yaml)
            ->index()
            ->runtime('messenger', ['transports' => [['name' => 'async', 'failure' => false]], 'complete' => true])
        ;
        $kit->get(AnalysisSettingsRegistry::class)->configureWorkspace(new ProjectAnalysisSettings(environment: $environment));

        self::assertSame($expectedCodes, $kit->codes($kit->get(MessengerDiagnosticProvider::class)->diagnostics($kit->document($uri))));
    }

    /** @return iterable<string, array{string, string, string, list<string>}> */
    public static function environmentScopedTransportProvider(): iterable
    {
        $directoryYaml = <<<'YAML'
            framework:
                messenger:
                    transports:
                        sync: 'sync://'
                    routing:
                        'App\Message\Ping': sync
            YAML;
        $sectionYaml = <<<'YAML'
            when@test:
                framework:
                    messenger:
                        transports:
                            sync: 'sync://'
                        routing:
                            'App\Message\Ping': sync
            YAML;

        yield 'inactive environment section' => ['file:///workspace/config/packages/messenger.yaml', $sectionYaml, 'dev', []];
        yield 'active environment section' => ['file:///workspace/config/packages/messenger.yaml', $sectionYaml, 'test', ['messenger.unknown_transport']];
        yield 'base section' => ['file:///workspace/config/packages/messenger.yaml', $directoryYaml, 'dev', ['messenger.unknown_transport']];
    }

    public function testParsesOnlyDocumentsDeclaringRuntimeHandlers(): void
    {
        $handlerUri = 'file:///workspace/src/Handler.php';
        $handlerText = self::handlerSource('string');
        $serviceUri = 'file:///workspace/src/Service.php';
        $serviceText = "<?php\nnamespace App;\nfinal class Service { public function __invoke(string \$message): void {} }\n";
        $documents = new DocumentStore();
        $documents->open(new Document($handlerUri, 'php', 1, $handlerText));
        $documents->open(new Document($serviceUri, 'php', 1, $serviceText));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $converter = new PositionConverter();
        $classExtractor = new PhpClassDeclarationExtractor($converter, new TolerantPhpParser(new Parser()));
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $classIndexes->forProject($project)->replace(
            new DependencyInjectionSourceFacts($handlerUri, classes: $classExtractor->extract($handlerUri, $handlerText)),
            new DependencyInjectionSourceFacts($serviceUri, classes: $classExtractor->extract($serviceUri, $serviceText)),
        );
        $indexes = new MessengerIndexRegistry();
        $indexes->forProject($project)->replace([], [], [], [
            new MessengerHandlerDeclaration('App\\Message', 'messenger.bus.default', 'handler', 'App\\Handler', '__invoke', 0, null),
        ], true);
        $parser = new class(new TolerantPhpParser(new Parser())) implements PhpParserInterface {
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
        };
        $requests = new ProviderRequests($documents, $projects, $converter);
        $provider = new MessengerDiagnosticProvider(
            new LspProtocolMapper(),
            $indexes,
            new MessengerSourceIndexRegistry(),
            $classIndexes,
            $parser,
            $converter,
            EnvironmentScopes::resolver(),
        );

        self::assertSame([], $provider->diagnostics($requests->document($serviceUri)));
        self::assertSame([], $parser->sources);
        self::assertSame(['messenger.invalid_handler_signature'], array_column($provider->diagnostics($requests->document($handlerUri)), 'code'));
        self::assertSame([$handlerText], $parser->sources);
    }

    public function testExtractsBusNamesOnlyFromMessengerBusNameStampInstantiations(): void
    {
        $extractor = $this->extractor();
        $text = <<<'PHP'
            <?php
            namespace App;

            use Symfony\Component\Messenger\Stamp\BusNameStamp as Stamp;
            use Vendor\Other\BusNameStamp;

            final class Documentation
            {
                public const EXAMPLE = 'new BusNameStamp("documented.bus")';

                public function stamps(): array
                {
                    return [
                        new Stamp('aliased.bus'),
                        new \Symfony\Component\Messenger\Stamp\BusNameStamp('qualified.bus'),
                        new BusNameStamp('vendor.bus'),
                    ];
                }
            }
            PHP;

        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Documentation.php', 'php', $text));

        self::assertSame(['aliased.bus', 'qualified.bus'], array_map(static fn ($symbol): string => $symbol->name, $facts->symbols));
    }

    public function testIgnoresCommentedPhpMessengerConstructs(): void
    {
        $extractor = $this->extractor();
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

        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Handler.php', 'php', $text));

        self::assertSame([], $facts->symbols);
        self::assertSame([], $facts->handlers);
    }

    /** @param list<string> $expectedLabels */
    #[DataProvider('messageHandlerAttributeCompletionProvider')]
    public function testCompletesMessengerNamesOnlyInResolvedHandlerAttributes(string $text, array $expectedLabels): void
    {
        $uri = 'file:///workspace/src/Handler.php';
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $converter = new PositionConverter();
        $indexes = new MessengerIndexRegistry();
        $indexes->forProject($project)->replace([new MessengerBus('command.bus', true)], [], [], [], true);
        $provider = new MessengerCompletionProvider(
            $converter,
            new LspProtocolMapper(),
            $indexes,
            new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
            new CommentParserRegistry(['php' => new PhpCommentParser(), 'yaml' => new YamlCommentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))]),
            new TolerantPhpParser(new Parser()),
        );
        $position = $converter->toPosition($text, \strlen($text));

        self::assertSame($expectedLabels, array_column($provider->complete((new ProviderRequests($documents, $projects))->positioned(LspRequests::position($uri, $position))), 'label'));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function messageHandlerAttributeCompletionProvider(): iterable
    {
        yield 'aliased attribute' => [<<<'PHP'
            <?php
            use Symfony\Component\Messenger\Attribute\AsMessageHandler as Handler;

            #[Handler(bus: 'command
            PHP, ['command.bus']];
        yield 'fully qualified attribute' => [<<<'PHP'
            <?php
            #[\Symfony\Component\Messenger\Attribute\AsMessageHandler(bus: 'command
            PHP, ['command.bus']];
        yield 'unrelated attribute with the same short name' => [<<<'PHP'
            <?php
            use App\Attribute\AsMessageHandler;

            #[AsMessageHandler(bus: 'command
            PHP, []];
    }

    public function testOffersNoMessengerCompletionsInsidePhpComments(): void
    {
        $uri = 'file:///workspace/src/Service.php';
        $text = "<?php // new BusNameStamp('comma";
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $converter = new PositionConverter();
        $indexes = new MessengerIndexRegistry();
        $indexes->forProject($project)->replace([new MessengerBus('command.bus', true)], [], [], [], true);
        $provider = new MessengerCompletionProvider(
            $converter,
            new LspProtocolMapper(),
            $indexes,
            new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
            new CommentParserRegistry(['php' => new PhpCommentParser(), 'yaml' => new YamlCommentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))]),
            new TolerantPhpParser(new Parser()),
        );
        $position = $converter->toPosition($text, \strlen($text));

        self::assertSame([], $provider->complete((new ProviderRequests($documents, $projects))->positioned(LspRequests::position($uri, $position))));
    }

    private function extractor(): MessengerExtractor
    {
        return (new ProjectTestKit())->get(MessengerExtractor::class);
    }

    private static function handlerSource(string $type): string
    {
        return <<<PHP
            <?php
            namespace App;

            class BaseHandler {}

            final class Handler extends BaseHandler
            {
                public function __invoke({$type} \$message): void {}
            }
            PHP;
    }
}
