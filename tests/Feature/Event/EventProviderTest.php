<?php

namespace Symfony\Lsp\Tests\Feature\Event;

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
use Symfony\Lsp\Feature\Event\EventExtractor;
use Symfony\Lsp\Feature\Event\EventIndexRegistry;
use Symfony\Lsp\Feature\Event\EventListener;
use Symfony\Lsp\Feature\Event\EventProvider;
use Symfony\Lsp\Feature\Event\EventSourceIndexRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class EventProviderTest extends TestCase
{
    public function testExtractsHighConfidenceEventReferences(): void
    {
        $converter = new PositionConverter();
        $extractor = new EventExtractor($converter);
        $php = <<<'PHP'
<?php
namespace App;
use App\Event\OrderPlaced;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
final class Subscriber
{
    public function __construct(private EventDispatcherInterface $dispatcher) {}
    public static function getSubscribedEvents(): array
    {
        return [OrderPlaced::class => 'onOrderPlaced', 'legacy.order_placed' => 'onLegacy'];
    }
    public function run(): void
    {
        $this->dispatcher->dispatch(new OrderPlaced());
        $this->dispatcher->dispatch(new OrderPlaced(), 'legacy.order_placed');
        $other->dispatch(new NotAnEvent());
    }
}
PHP;

        $facts = $extractor->extract('file:///workspace/src/Subscriber.php', 'php', $php);
        self::assertSame(
            ['App\\Event\\OrderPlaced', 'legacy.order_placed'],
            array_values(array_unique(array_map(static fn ($symbol): string => $symbol->name(), $facts->symbols()))),
        );

        $yaml = <<<'YAML'
services:
  App\EventListener\AuditOrder:
    tags:
      - { name: kernel.event_listener, event: legacy.order_placed }
YAML;
        self::assertSame(['legacy.order_placed'], array_map(static fn ($symbol): string => $symbol->name(), $extractor->extract('file:///workspace/config/services.yaml', 'yaml', $yaml)->symbols()));
    }

    public function testCompletesHoversNavigatesDiagnosesAndProvidesCodeLenses(): void
    {
        $eventUri = 'file:///workspace/src/Event/OrderPlaced.php';
        $event = "<?php\nnamespace App\\Event;\nfinal class OrderPlaced {}\n";
        $listenerUri = 'file:///workspace/src/EventListener/NotifyCustomer.php';
        $listener = <<<'PHP'
<?php
namespace App\EventListener;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
#[AsEventListener(event: 'App\Event\OrderPlaced', method: 'onOrderPlaced')]
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
        $extractor = new EventExtractor($converter);
        $indexes = new EventIndexRegistry();
        $indexes->forProject($project)->replace(
            [new Event('App\\Event\\OrderPlaced', 'App\\Event\\OrderPlaced')],
            [new EventListener('App\\Event\\OrderPlaced', 'App\\EventListener\\NotifyCustomer', 'onOrderPlaced', 10)],
            true,
        );
        $sourceIndexes = new EventSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace(
            $extractor->extract($listenerUri, 'php', $listener),
            $extractor->extract($dispatcherUri, 'php', $dispatcher),
        );
        $classExtractor = new PhpClassDeclarationExtractor($converter);
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $classIndexes->forProject($project)->replace(
            new DependencyInjectionSourceFacts($eventUri, classes: $classExtractor->extract($eventUri, $event)),
            new DependencyInjectionSourceFacts($listenerUri, classes: $classExtractor->extract($listenerUri, $listener)),
            new DependencyInjectionSourceFacts($dispatcherUri, classes: $classExtractor->extract($dispatcherUri, $dispatcher)),
        );
        $provider = new EventProvider(new DocumentContextResolver($documents, $projects), $documents, $projects, $converter, $indexes, $sourceIndexes, $extractor, $classExtractor, $classIndexes);

        $completionOffset = strpos($dispatcher, "App\\Event\\Ord');") + \strlen('App\\Event\\Ord');
        self::assertSame(['App\\Event\\OrderPlaced'], array_column($provider->complete($this->params($dispatcherUri, $converter->toPosition($dispatcher, $completionOffset))) ?? [], 'label'));
        $dispatchPosition = $converter->toPosition($dispatcher, (int) strpos($dispatcher, 'OrderPlaced());'));
        self::assertStringContainsString('Symfony event', json_encode($provider->hover($this->params($dispatcherUri, $dispatchPosition)), \JSON_THROW_ON_ERROR));
        self::assertSame([$eventUri, $listenerUri], array_column($provider->definition($this->params($dispatcherUri, $dispatchPosition)) ?? [], 'uri'));

        $eventPosition = $converter->toPosition($event, (int) strpos($event, 'OrderPlaced'));
        self::assertContains($dispatcherUri, array_column($provider->references($this->params($eventUri, $eventPosition)) ?? [], 'uri'));
        self::assertSame(['event.invalid_listener_method'], array_column($provider->diagnostics(['textDocument' => ['uri' => $invalidUri]]) ?? [], 'code'));
        $eventLens = $provider->codeLenses(['textDocument' => ['uri' => $eventUri]])[0] ?? null;
        self::assertIsArray($eventLens);
        self::assertIsArray($eventLens['command'] ?? null);
        self::assertSame('1 event listener', $eventLens['command']['title'] ?? null);
        $listenerLens = $provider->codeLenses(['textDocument' => ['uri' => $listenerUri]])[0] ?? null;
        self::assertIsArray($listenerLens);
        self::assertIsArray($listenerLens['command'] ?? null);
        self::assertSame('Listens to 1 event', $listenerLens['command']['title'] ?? null);
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function params(string $uri, Position $position): array
    {
        return ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]];
    }
}
