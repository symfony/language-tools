<?php

namespace Symfony\Lsp\Tests\Feature\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\Messenger\MessengerBus;
use Symfony\Lsp\Feature\Messenger\MessengerExtractor;
use Symfony\Lsp\Feature\Messenger\MessengerHandler;
use Symfony\Lsp\Feature\Messenger\MessengerIndexRegistry;
use Symfony\Lsp\Feature\Messenger\MessengerMessage;
use Symfony\Lsp\Feature\Messenger\MessengerProvider;
use Symfony\Lsp\Feature\Messenger\MessengerSourceIndexRegistry;
use Symfony\Lsp\Feature\Messenger\MessengerTransport;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

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
        $facts = (new MessengerExtractor(new PositionConverter()))->extract('file:///workspace/config/packages/messenger.yaml', 'yaml', $text);

        self::assertSame(
            ['command.bus', 'async', 'failed', 'App\\Message\\Ping', 'async', 'command.bus', 'failed'],
            array_map(static fn ($symbol): string => $symbol->name(), $facts->symbols()),
        );
        self::assertSame(
            [true, true, true, false, false, false, false],
            array_map(static fn ($symbol): bool => $symbol->isDeclaration(), $facts->symbols()),
        );
        $phpFacts = (new MessengerExtractor(new PositionConverter()))->extract('file:///workspace/src/Example.php', 'php', "<?php\nfoo(bus: 'not_messenger');\n\$dispatcher->dispatch(new NotAMessage());\n");
        self::assertSame([], $phpFacts->symbols());
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
        $controller = "<?php\nnamespace App\\Controller;\nuse App\\Message\\Ping;\nuse Symfony\\Component\\Messenger\\MessageBusInterface;\nfinal class PingController { public function __construct(private MessageBusInterface \$bus) {} public function send(): void { \$this->bus->dispatch(new Ping()); } }\n";
        $documents = new DocumentStore();
        $documents->open(new Document($yamlUri, 'yaml', 1, $yaml));
        $documents->open(new Document($messageUri, 'php', 1, $message));
        $documents->open(new Document($handlerUri, 'php', 1, $handler));
        $documents->open(new Document($controllerUri, 'php', 1, $controller));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $extractor = new MessengerExtractor($converter);
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
        $classExtractor = new PhpClassDeclarationExtractor($converter);
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $classIndexes->forProject($project)->replace(
            new DependencyInjectionSourceFacts($messageUri, classes: $classExtractor->extract($messageUri, $message)),
            new DependencyInjectionSourceFacts($handlerUri, classes: $classExtractor->extract($handlerUri, $handler)),
        );
        $provider = new MessengerProvider(new DocumentContextResolver($documents, $projects), $documents, $projects, $converter, $indexes, $sourceIndexes, $extractor, $classExtractor, $classIndexes);

        $completionParams = $this->params($yamlUri, $converter->toPosition($yaml, strpos($yaml, 'command.bus }') + 4));
        self::assertSame(['command.bus'], array_column($provider->complete($completionParams) ?? [], 'label'));
        $routingCompletion = $this->params($yamlUri, $converter->toPosition($yaml, strpos($yaml, "async\nservices") + 3));
        self::assertSame(['async'], array_column($provider->complete($routingCompletion) ?? [], 'label'));
        $hover = $provider->hover($this->params($yamlUri, $converter->toPosition($yaml, strpos($yaml, 'async }') + 2)));
        self::assertStringContainsString('Messenger transport', json_encode($hover, \JSON_THROW_ON_ERROR));
        self::assertSame([$yamlUri], array_column($provider->definition($this->params($yamlUri, $converter->toPosition($yaml, strpos($yaml, 'command.bus') + 2))) ?? [], 'uri'));
        self::assertSame(['messenger.unknown_bus'], array_column($provider->diagnostics(['textDocument' => ['uri' => $yamlUri]]) ?? [], 'code'));
        self::assertSame(['messenger.invalid_handler_signature'], array_column($provider->diagnostics(['textDocument' => ['uri' => $handlerUri]]) ?? [], 'code'));

        $messagePosition = $converter->toPosition($message, (int) strpos($message, 'Ping'));
        self::assertSame([$handlerUri], array_column($provider->definition($this->params($messageUri, $messagePosition)) ?? [], 'uri'));
        self::assertContains($controllerUri, array_column($provider->references($this->params($messageUri, $messagePosition)) ?? [], 'uri'));
        $dispatchPosition = $converter->toPosition($controller, (int) strrpos($controller, 'Ping'));
        self::assertSame([$messageUri, $handlerUri], array_column($provider->definition($this->params($controllerUri, $dispatchPosition)) ?? [], 'uri'));
        $codeLens = $provider->codeLenses(['textDocument' => ['uri' => $messageUri]])[0] ?? null;
        self::assertIsArray($codeLens);
        self::assertIsArray($codeLens['command'] ?? null);
        self::assertSame('1 Messenger handler', $codeLens['command']['title'] ?? null);
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function params(string $uri, Position $position): array
    {
        return ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]];
    }
}
