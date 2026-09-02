<?php

namespace Symfony\Lsp\Tests\Feature\Event;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\Event\Event;
use Symfony\Lsp\Feature\Event\EventCodeLensProvider;
use Symfony\Lsp\Feature\Event\EventCompletionProvider;
use Symfony\Lsp\Feature\Event\EventDiagnosticProvider;
use Symfony\Lsp\Feature\Event\EventExtractor;
use Symfony\Lsp\Feature\Event\EventIndexRegistry;
use Symfony\Lsp\Feature\Event\EventListener;
use Symfony\Lsp\Feature\Event\EventRelationshipProvider;
use Symfony\Lsp\Feature\Event\EventRelationshipResolver;
use Symfony\Lsp\Feature\Event\EventSourceIndexRegistry;
use Symfony\Lsp\Feature\Event\EventSubscriberMapAnalyzer;
use Symfony\Lsp\Feature\Event\EventYamlListenerAnalyzer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpCapturedReceiverResolver;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class EventProviderTest extends TestCase
{
    public function testExtractsHighConfidenceEventReferences(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->extractor($converter);
        $php = <<<'PHP'
<?php
namespace App;
use App\Event\{OrderPlaced};
use Symfony\Component\EventDispatcher\Attribute\AsEventListener as Listener;
use Symfony\Contracts\EventDispatcher\{EventDispatcherInterface};
#[Listener(event: OrderPlaced::class)]
final class Subscriber
{
    public function __construct(private EventDispatcherInterface $dispatcher) {}
    public static function getSubscribedEvents(): array
    {
        return [OrderPlaced::class => 'onOrderPlaced', 'legacy.order_placed' => 'on}Legacy', 'after.brace' => 'onAfter'];
    }
    public function run(): void
    {
        $this->dispatcher->dispatch(new OrderPlaced());
        $this->dispatcher->dispatch(new OrderPlaced(), 'legacy.order_placed');
        $other->dispatch(new NotAnEvent());
    }
}
PHP;

        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Subscriber.php', 'php', $php));
        $names = [];
        $declarations = 0;
        foreach ($facts->symbols as $symbol) {
            $names[$symbol->name] = true;
            $declarations += $symbol->declaration ? 1 : 0;
        }
        self::assertSame(['App\\Event\\OrderPlaced', 'legacy.order_placed', 'after.brace'], array_keys($names));
        self::assertSame(4, $declarations);
        self::assertSame(3, \count($facts->symbols) - $declarations);

        $yaml = <<<'YAML'
services:
  App\EventListener\AuditOrder:
    tags:
      - { name: kernel.event_listener, event: legacy.order_placed }
YAML;
        self::assertSame('legacy.order_placed', $extractor->extract(new SourceDocument('file:///workspace/config/services.yaml', 'yaml', $yaml))->symbols[0]->name);
    }

    public function testIgnoresClassReferencesEmbeddedInListenerEventExpressions(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Listener.php', 'php', <<<'PHP'
            <?php
            namespace App;

            use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

            #[AsEventListener(event: EVENT_PREFIX . IgnoredPrefixEvent::class)]
            #[AsEventListener(event: IgnoredSuffixEvent::class . EVENT_SUFFIX)]
            final class Listener
            {
            }
            PHP));

        self::assertSame([], $facts->symbols);
    }

    public function testPreservesGroupedRepeatableListenerAttributes(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Listener.php', 'php', <<<'PHP'
            <?php
            namespace App;

            use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

            #[AsEventListener(event: 'app.first'), AsEventListener(event: 'app.second')]
            final class Listener
            {
            }
            PHP));

        self::assertSame(['app.first', 'app.second'], array_map(static fn ($symbol): string => $symbol->name, $facts->symbols));
        self::assertCount(2, $facts->listeners);
    }

    public function testExtractsAndCompletesYamlListenerEventsWithByteExactRanges(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->extractor($converter);
        $text = <<<'YAML'
            services:
              App\InlineListener:
                tags:
                  - { name: kernel.event_listener, event: 'legacy.order_placed' }
              App\BlockListener:
                tags:
                  - name: kernel.event_listener
                    event: App\Event\OrderPlaced
            YAML;
        $facts = $extractor->extract(new SourceDocument('file:///workspace/config/services.yaml', 'yaml', $text));

        self::assertSame(['legacy.order_placed', 'App\Event\OrderPlaced'], array_map(static fn ($symbol): string => $symbol->name, $facts->symbols));
        self::assertSame(
            ['legacy.order_placed', 'App\Event\OrderPlaced'],
            array_map(static function ($symbol) use ($converter, $text): string {
                $start = $converter->toByteOffset($text, $symbol->range->start);
                $end = $converter->toByteOffset($text, $symbol->range->end);

                return substr($text, $start, $end - $start);
            }, $facts->symbols),
        );

        $inline = "services:\n  App\\Listener:\n    tags:\n      - { name: kernel.event_listener, event: 'legacy.or";
        $block = "services:\n  App\\Listener:\n    tags:\n      - name: kernel.event_listener\n        event: App\\Event\\Ord";
        self::assertSame('legacy.or', $extractor->completionPrefix('yaml', $inline, \strlen($inline)));
        self::assertSame('App\Event\Ord', $extractor->completionPrefix('yaml', $block, \strlen($block)));
    }

    public function testScopesEventDispatcherParametersToTheirMethod(): void
    {
        $extractor = $this->extractor();
        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Dispatch.php', 'php', <<<'PHP'
            <?php
            namespace App;

            use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

            final class Dispatch
            {
                public function event(EventDispatcherInterface $dispatcher): void
                {
                    $dispatcher->dispatch(new ExpectedEvent());
                }

                public function unrelated(object $dispatcher): void
                {
                    $dispatcher->dispatch(new IgnoredEvent());
                }
            }
            PHP));

        self::assertSame(['App\ExpectedEvent'], array_map(static fn ($symbol): string => $symbol->name, $facts->symbols));
    }

    public function testIndexesEventReferencesCapturedInsideClosures(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Dispatch.php', 'php', <<<'PHP'
            <?php
            namespace App;

            use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

            final class Dispatch
            {
                public function dispatch(EventDispatcherInterface $dispatcher): void
                {
                    $closure = function () use ($dispatcher): void {
                        $dispatcher->dispatch(new ClosureEvent());
                    };
                    $arrow = fn () => $dispatcher->dispatch(new ArrowEvent());
                    $uncaptured = function (): void {
                        $dispatcher->dispatch(new UncapturedEvent());
                    };
                    $shadowed = fn ($dispatcher) => $dispatcher->dispatch(new ShadowedEvent());
                }
            }
            PHP));

        self::assertSame(['App\ClosureEvent', 'App\ArrowEvent'], array_map(static fn ($symbol): string => $symbol->name, $facts->symbols));
    }

    public function testIndexesOnlyDirectPositionalEventCreations(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->extractor($converter);
        $text = <<<'PHP'
            <?php
            namespace App;

            use App\Event\OrderPlaced as AliasedEvent;
            use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

            final class Dispatch
            {
                public function __construct(private EventDispatcherInterface $dispatcher) {}

                public function dispatch(): void
                {
                    $this->dispatcher->dispatch(new AliasedEvent());
                    $this->dispatcher->dispatch(new \App\Event\QualifiedEvent());
                    $this->dispatcher->dispatch(unrelated: new IgnoredEvent());
                    $this->dispatcher->dispatch(...[new SpreadEvent()]);
                    $this->dispatcher->dispatch(factory(new NestedEvent()));
                }
            }
            PHP;

        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Dispatch.php', 'php', $text));
        $ranges = array_map(static function ($symbol) use ($converter, $text): string {
            $start = $converter->toByteOffset($text, $symbol->range->start);
            $end = $converter->toByteOffset($text, $symbol->range->end);

            return substr($text, $start, $end - $start);
        }, $facts->symbols);

        self::assertSame(['App\Event\OrderPlaced', 'App\Event\QualifiedEvent'], array_map(static fn ($symbol): string => $symbol->name, $facts->symbols));
        self::assertSame(['AliasedEvent', '\\App\Event\QualifiedEvent'], $ranges);
    }

    public function testCompletesHoversNavigatesDiagnosesAndProvidesCodeLenses(): void
    {
        $eventUri = 'file:///workspace/src/Event/OrderPlaced.php';
        $event = "<?php\nnamespace App\\Event;\nfinal class OrderPlaced {}\n";
        $listenerUri = 'file:///workspace/src/EventListener/NotifyCustomer.php';
        $listener = <<<'PHP'
<?php
namespace App\EventListener;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener as Listener;
#[Listener(event: 'App\Event\OrderPlaced', method: 'onOrderPlaced')]
final class NotifyCustomer
{
    public function onOrderPlaced(): void {}
}
PHP;
        $dispatcherUri = 'file:///workspace/src/DispatchOrder.php';
        $dispatcher = <<<'PHP'
<?php
namespace App;
use App\Event\OrderPlaced;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
final class DispatchOrder
{
    public function __construct(private EventDispatcherInterface $dispatcher) {}
    public function dispatch(): void
    {
        $this->dispatcher->dispatch(new OrderPlaced());
        $this->dispatcher->dispatch(new OrderPlaced(), 'App\Event\Ord');
    }
}
PHP;
        $invalidUri = 'file:///workspace/src/EventListener/InvalidListener.php';
        $invalid = <<<'PHP'
<?php
namespace App\EventListener;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
#[AsEventListener(event: 'App\Event\OrderPlaced', method: 'missing')]
final class InvalidListener
{
}
PHP;
        $documents = new DocumentStore();
        foreach ([[$eventUri, $event], [$listenerUri, $listener], [$dispatcherUri, $dispatcher], [$invalidUri, $invalid]] as [$uri, $text]) {
            $documents->open(new Document($uri, 'php', 1, $text));
        }
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $extractor = $this->extractor($converter);
        $indexes = new EventIndexRegistry();
        $indexes->forProject($project)->replace(
            [new Event('App\\Event\\OrderPlaced', 'App\\Event\\OrderPlaced')],
            [new EventListener('App\\Event\\OrderPlaced', 'App\\EventListener\\NotifyCustomer', 'onOrderPlaced', 10)],
            true,
        );
        $sourceIndexes = new EventSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace(
            $extractor->extract(new SourceDocument($listenerUri, 'php', $listener)),
            $extractor->extract(new SourceDocument($dispatcherUri, 'php', $dispatcher)),
        );
        $classExtractor = new PhpClassDeclarationExtractor($converter, new TolerantPhpParser(new Parser()));
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $classIndexes->forProject($project)->replace(
            new DependencyInjectionSourceFacts($eventUri, classes: $classExtractor->extract($eventUri, $event)),
            new DependencyInjectionSourceFacts($listenerUri, classes: $classExtractor->extract($listenerUri, $listener)),
            new DependencyInjectionSourceFacts($dispatcherUri, classes: $classExtractor->extract($dispatcherUri, $dispatcher)),
        );
        $documentResolver = new DocumentContextResolver($documents, $projects);
        $protocol = new LspProtocolMapper();
        $relationshipResolver = new EventRelationshipResolver($documentResolver, $converter, $protocol, $sourceIndexes, $extractor, $classExtractor, $classIndexes);
        $completionProvider = new EventCompletionProvider($documentResolver, $converter, $protocol, $indexes, $extractor);
        $relationshipProvider = new EventRelationshipProvider($protocol, $indexes, $relationshipResolver);
        $diagnosticProvider = new EventDiagnosticProvider($documentResolver, $protocol, $extractor);
        $codeLensProvider = new EventCodeLensProvider($documentResolver, $protocol, $indexes, $classExtractor, $relationshipResolver);

        $completionOffset = strpos($dispatcher, "App\\Event\\Ord');") + \strlen('App\\Event\\Ord');
        self::assertSame(['App\\Event\\OrderPlaced'], array_column($completionProvider->complete($this->params($dispatcherUri, $converter->toPosition($dispatcher, $completionOffset))) ?? [], 'label'));
        $dispatchPosition = $converter->toPosition($dispatcher, (int) strpos($dispatcher, 'OrderPlaced());'));
        self::assertStringContainsString('Symfony event', json_encode($relationshipProvider->hover($this->params($dispatcherUri, $dispatchPosition)), \JSON_THROW_ON_ERROR));
        self::assertSame([$eventUri, $listenerUri], array_column($relationshipProvider->definition($this->params($dispatcherUri, $dispatchPosition)) ?? [], 'uri'));

        $eventPosition = $converter->toPosition($event, (int) strpos($event, 'OrderPlaced'));
        self::assertContains($dispatcherUri, array_column($relationshipProvider->references($this->params($eventUri, $eventPosition)) ?? [], 'uri'));
        self::assertSame(['event.invalid_listener_method'], array_column($diagnosticProvider->diagnostics(['textDocument' => ['uri' => $invalidUri]]) ?? [], 'code'));
        $eventLens = $codeLensProvider->codeLenses(['textDocument' => ['uri' => $eventUri]])[0] ?? null;
        self::assertIsArray($eventLens);
        self::assertIsArray($eventLens['command'] ?? null);
        self::assertSame('1 event listener', $eventLens['command']['title'] ?? null);
        $listenerLens = $codeLensProvider->codeLenses(['textDocument' => ['uri' => $listenerUri]])[0] ?? null;
        self::assertIsArray($listenerLens);
        self::assertIsArray($listenerLens['command'] ?? null);
        self::assertSame('Listens to 1 event', $listenerLens['command']['title'] ?? null);
    }

    public function testIgnoresCommentedPhpEventConstructs(): void
    {
        $extractor = $this->extractor();
        $text = <<<'PHP'
            <?php
            namespace App;

            use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

            final class Listener
            {
                public function __construct(private EventDispatcherInterface $dispatcher) {}

                public function listen(): void
                {
                    // #[AsEventListener(event: 'commented.attribute')]
                    // $this->dispatcher->dispatch(new CommentedEvent());
                    // $this->dispatcher->dispatch(new CommentedEvent(), 'commented.name');
                }
            }
            PHP;

        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Listener.php', 'php', $text));

        self::assertSame([], $facts->symbols);
        self::assertSame([], $facts->listeners);
    }

    private function extractor(?PositionConverter $converter = null): EventExtractor
    {
        $converter ??= new PositionConverter();

        return new EventExtractor(
            $converter,
            new TolerantPhpParser(new Parser()),
            new PhpCommentParser(),
            new PhpCapturedReceiverResolver(new BalancedDelimiterMatcher()),
            new EventYamlListenerAnalyzer($converter),
            new EventSubscriberMapAnalyzer($converter, new BalancedDelimiterMatcher()),
        );
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function params(string $uri, Position $position): array
    {
        return ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line, 'character' => $position->character]];
    }

    #[DataProvider('eventListenerAttributeCompletionProvider')]
    public function testCompletesEventNamesOnlyInResolvedEventListenerAttributes(string $text, ?string $expectedPrefix): void
    {
        $extractor = $this->extractor();

        self::assertSame($expectedPrefix, $extractor->completionPrefix('php', $text, \strlen($text)));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function eventListenerAttributeCompletionProvider(): iterable
    {
        yield 'aliased attribute' => [<<<'PHP'
            <?php
            use Symfony\Component\EventDispatcher\Attribute\AsEventListener as Listener;

            #[Listener(event: 'app.or
            PHP, 'app.or'];
        yield 'fully qualified attribute' => [<<<'PHP'
            <?php
            #[\Symfony\Component\EventDispatcher\Attribute\AsEventListener(event: 'app.or
            PHP, 'app.or'];
        yield 'unrelated attribute with the same short name' => [<<<'PHP'
            <?php
            use App\Attribute\AsEventListener;

            #[AsEventListener(event: 'app.or
            PHP, null];
    }

    public function testOffersNoEventCompletionsInsidePhpComments(): void
    {
        $extractor = $this->extractor();
        $text = "<?php\n// #[AsEventListener(event: 'app.or";

        self::assertNull($extractor->completionPrefix('php', $text, \strlen($text)));
    }
}
