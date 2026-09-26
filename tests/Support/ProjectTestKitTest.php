<?php

namespace Symfony\Lsp\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\Event\EventIndexRegistry;
use Symfony\Lsp\Parser\Php\LastResultPhpParser;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class ProjectTestKitTest extends TestCase
{
    public function testServesTheWiringTheServerRunsOn(): void
    {
        $kit = new ProjectTestKit();

        self::assertInstanceOf(LastResultPhpParser::class, $kit->get(PhpParserInterface::class));
        self::assertSame($kit->get(DocumentStore::class), $kit->get(DocumentStore::class));
        self::assertNotSame($kit->get(DocumentStore::class), (new ProjectTestKit())->get(DocumentStore::class));
    }

    public function testOpensDocumentsWithTheLanguageTheirNameTells(): void
    {
        $kit = new ProjectTestKit();
        $kit->open('file:///workspace/src/Kernel.php', "<?php\nclass Kernel {}\n");
        $kit->open('file:///workspace/config/services.yaml', "services:\n");
        $kit->open('file:///workspace/templates/index.html.twig', '{{ title }}');

        $documents = $kit->get(DocumentStore::class);
        self::assertSame(['php', 'yaml', 'twig'], array_map(static fn ($document): string => $document->languageId, $documents->all()));
        self::assertSame("<?php\nclass Kernel {}\n", $documents->get('file:///workspace/src/Kernel.php')?->text);
        self::assertSame(['line' => 1, 'character' => 6], $kit->at('file:///workspace/src/Kernel.php', 'Kernel {}')['position']);
    }

    public function testIndexesOpenDocumentsThroughEverySourceIndexProvider(): void
    {
        $kit = (new ProjectTestKit())
            ->open('file:///workspace/src/Notifier.php', "<?php\nnamespace App;\nfinal class Notifier {}\n")
            ->index()
        ;

        $classes = $kit->get(DependencyInjectionSourceIndexRegistry::class)->forProject($kit->project());
        self::assertTrue($classes->hasScannedSources());
        self::assertSame(
            ['file:///workspace/src/Notifier.php'],
            array_map(static fn ($declaration): string => $declaration->uri, $classes->classDeclarations('App\\Notifier')),
        );
    }

    public function testIndexesTheGivenSourcesInsteadOfTheOpenOnes(): void
    {
        $kit = (new ProjectTestKit())
            ->open('file:///workspace/src/Notifier.php', "<?php\nnamespace App;\nfinal class Renamed {}\n")
            ->index(['file:///workspace/src/Notifier.php' => "<?php\nnamespace App;\nfinal class Notifier {}\n"])
        ;

        $classes = $kit->get(DependencyInjectionSourceIndexRegistry::class)->forProject($kit->project());
        self::assertSame([], $classes->classDeclarations('App\\Renamed'));
        self::assertCount(1, $classes->classDeclarations('App\\Notifier'));
    }

    public function testLoadsRuntimeMetadataSectionsThroughTheirLoader(): void
    {
        $kit = (new ProjectTestKit())->runtime('events', [
            'events' => [['name' => 'App\\Event\\OrderPlaced']],
            'listeners' => [['event' => 'App\\Event\\OrderPlaced', 'class' => 'App\\EventListener\\NotifyCustomer', 'method' => 'onOrderPlaced', 'priority' => 10]],
            'complete' => true,
        ]);

        $events = $kit->get(EventIndexRegistry::class)->forProject($kit->project());
        self::assertTrue($events->isComplete());
        self::assertSame('App\\Event\\OrderPlaced', $events->event('App\\Event\\OrderPlaced')?->name);
        self::assertSame(
            ['App\\EventListener\\NotifyCustomer'],
            array_map(static fn ($listener): string => $listener->className, $events->listenersForEvent('App\\Event\\OrderPlaced')),
        );
    }

    public function testShapesTheAnswersOfTheProvidersItServes(): void
    {
        $kit = new ProjectTestKit();

        self::assertSame(['format'], $kit->labels([['label' => 'format']]));
        self::assertSame(['console.unknown_option'], $kit->codes([['code' => 'console.unknown_option']]));
        self::assertSame(['Unknown option.'], $kit->messages([['message' => 'Unknown option.']]));
        self::assertSame(['file:///workspace/src/Kernel.php'], $kit->targets([['uri' => 'file:///workspace/src/Kernel.php']]));
        self::assertSame(['1 event listener'], $kit->titles([['command' => ['title' => '1 event listener']]]));
        self::assertSame('Symfony event', $kit->hoverText(['contents' => ['kind' => 'markdown', 'value' => 'Symfony event']]));
        self::assertSame([], $kit->labels(null));
        self::assertSame('', $kit->hoverText(null));
    }
}
