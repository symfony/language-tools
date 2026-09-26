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
        $definition = $workspace->write('tests/Support/TestContainer.php', '<?php');
        $key = TestContainer::key($workspace->path(), $definition);

        self::assertSame($key, TestContainer::key($workspace->path(), $definition));

        $workspace->write('README.md', 'documented');
        self::assertSame($key, TestContainer::key($workspace->path(), $definition), 'A file the wiring does not read keeps the key.');

        foreach ([
            'a changed container definition' => ['tests/Support/TestContainer.php', '<?php // changed'],
            'a changed service definition' => ['resources/services.php', '<?php // changed'],
            'a changed service class' => ['src/Feature/Console/ConsoleProvider.php', '<?php // changed'],
            'an added service class' => ['src/Feature/Console/ConsoleIndex.php', '<?php'],
            'an updated dependency' => ['composer.lock', '{"content-hash": "changed"}'],
        ] as $change => [$path, $contents]) {
            $workspace->write($path, $contents);
            $changedKey = TestContainer::key($workspace->path(), $definition);
            self::assertNotSame($key, $changedKey, \sprintf('The key of the dumped wiring covers %s.', $change));
            $key = $changedKey;
        }

        $workspace->cleanup();
    }

    public function testPrunesOnlyTheDumpsOlderThanTheCurrentOne(): void
    {
        $workspace = new TestWorkspace();
        $older = $workspace->write('TestContainerOlder.php', '<?php');
        $current = $workspace->write('TestContainerCurrent.php', '<?php');
        $newer = $workspace->write('TestContainerNewer.php', '<?php');
        $pending = $workspace->write('TestContainerPending.php.123', '<?php');
        touch($older, time() - 60);
        touch($current, time() - 30);
        touch($pending, time() - 60);

        TestContainer::prune($workspace->path(), $current);

        self::assertFileDoesNotExist($older);
        self::assertFileExists($current);
        self::assertFileExists($newer, 'A dump written after the current one may be about to be required by another process.');
        self::assertFileExists($pending);

        $workspace->cleanup();
    }
}
