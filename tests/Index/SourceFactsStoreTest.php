<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Index\SourceFactsOverlayOrder;
use Symfony\Lsp\Index\SourceFactsStore;

final class SourceFactsStoreTest extends TestCase
{
    /** @param list<string> $expected */
    #[DataProvider('overlayOrderProvider')]
    public function testAppliesTheSelectedOverlayOrder(SourceFactsOverlayOrder $order, array $expected): void
    {
        /** @var SourceFactsStore<StoreSourceFacts> $store */
        $store = new SourceFactsStore($order);
        $store->replaceSaved(new StoreSourceFacts('first', 'saved-first'), new StoreSourceFacts('second', 'saved-second'));
        $store->replaceOverlay(new StoreSourceFacts('first', 'overlay-first'));

        self::assertSame($expected, array_map(static fn (StoreSourceFacts $facts): string => $facts->value, $store->effective()));
    }

    /** @return iterable<string, array{SourceFactsOverlayOrder, list<string>}> */
    public static function overlayOrderProvider(): iterable
    {
        yield 'saved position' => [SourceFactsOverlayOrder::PreserveSavedPosition, ['overlay-first', 'saved-second']];
        yield 'overlays last' => [SourceFactsOverlayOrder::OverlaysLast, ['saved-second', 'overlay-first']];
    }

    public function testRestoresSavedFactsAfterRemovingAnOverlay(): void
    {
        /** @var SourceFactsStore<StoreSourceFacts> $store */
        $store = new SourceFactsStore();
        $store->replaceSaved(new StoreSourceFacts('first', 'saved-first'), new StoreSourceFacts('second', 'saved-second'));
        $store->replaceSavedFact(new StoreSourceFacts('third', 'saved-third'));
        $store->removeSaved('second');
        $store->replaceOverlay(new StoreSourceFacts('first', 'overlay-first'));
        $store->removeOverlay('first');

        self::assertSame(['saved-first', 'saved-third'], array_map(static fn (StoreSourceFacts $facts): string => $facts->value, $store->effective()));
    }
}

final class StoreSourceFacts implements SourceFactsInterface
{
    public function __construct(public readonly string $uri, public readonly string $value)
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function isEmpty(): bool
    {
        return '' === $this->value;
    }
}
