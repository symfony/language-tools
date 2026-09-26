<?php

namespace Symfony\Lsp\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Console\ConsoleProvider;
use Symfony\Lsp\Feature\Event\EventCompletionProvider;
use Symfony\Lsp\Index\SourceIndexProviderPipeline;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderRegistry;

final class TestContainerTest extends TestCase
{
    public function testServesThePrivateServicesOfTheCompiledWiring(): void
    {
        $container = TestContainer::create();

        self::assertInstanceOf(ConsoleProvider::class, $container->get(ConsoleProvider::class));
        self::assertInstanceOf(EventCompletionProvider::class, $container->get(EventCompletionProvider::class));
        self::assertInstanceOf(SourceIndexProviderPipeline::class, $container->get(SourceIndexProviderPipeline::class));
        self::assertInstanceOf(RuntimeSnapshotLoaderRegistry::class, $container->get(RuntimeSnapshotLoaderRegistry::class));
    }

    public function testHoldsNoServiceOfAnotherContainer(): void
    {
        self::assertNotSame(TestContainer::create(), TestContainer::create());
        self::assertNotSame(TestContainer::create()->get(ConsoleProvider::class), TestContainer::create()->get(ConsoleProvider::class));
    }

    public function testKeysTheDumpedWiringOnEverythingItIsDerivedFrom(): void
    {
        $workspace = new TestWorkspace();
        $workspace->write('resources/services.php', '<?php');
        $workspace->write('composer.lock', '{}');
        $workspace->write('src/Feature/Console/ConsoleProvider.php', '<?php');
        $key = TestContainer::key($workspace->path());

        self::assertSame($key, TestContainer::key($workspace->path()));

        $workspace->write('README.md', 'documented');
        self::assertSame($key, TestContainer::key($workspace->path()), 'A file the wiring does not read keeps the key.');

        foreach ([
            'a changed service definition' => ['resources/services.php', '<?php // changed'],
            'a changed service class' => ['src/Feature/Console/ConsoleProvider.php', '<?php // changed'],
            'an added service class' => ['src/Feature/Console/ConsoleIndex.php', '<?php'],
            'an updated dependency' => ['composer.lock', '{"content-hash": "changed"}'],
        ] as $change => [$path, $contents]) {
            $workspace->write($path, $contents);
            $changedKey = TestContainer::key($workspace->path());
            self::assertNotSame($key, $changedKey, \sprintf('The key of the dumped wiring covers %s.', $change));
            $key = $changedKey;
        }

        $workspace->cleanup();
    }
}
