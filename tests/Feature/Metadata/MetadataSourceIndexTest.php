<?php

namespace Symfony\Lsp\Tests\Feature\Metadata;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Metadata\MetadataSourceFacts;
use Symfony\Lsp\Feature\Metadata\MetadataSourceIndex;
use Symfony\Lsp\Feature\Metadata\MetadataSourceSymbol;
use Symfony\Lsp\Feature\Metadata\MetadataSymbolKind;

final class MetadataSourceIndexTest extends TestCase
{
    public function testInvalidatesCachedSymbolsAndNames(): void
    {
        $index = new MetadataSourceIndex();
        $first = $this->facts('file:///entity.php', 'first');
        $index->replace($first);

        self::assertSame([$first->symbols()[0]], $index->symbols(MetadataSymbolKind::SerializerGroup, 'first'));
        self::assertSame(['first'], $index->names(MetadataSymbolKind::SerializerGroup));

        $second = $this->facts('file:///entity.php', 'second');
        $index->replaceSource($second);

        self::assertSame([], $index->symbols(MetadataSymbolKind::SerializerGroup, 'first'));
        self::assertSame([$second->symbols()[0]], $index->symbols(MetadataSymbolKind::SerializerGroup, 'second'));
        self::assertSame(['second'], $index->names(MetadataSymbolKind::SerializerGroup));
    }

    public function testPreservesNumericStringNames(): void
    {
        $index = new MetadataSourceIndex();
        $index->replace($this->facts('file:///entity.php', '1'));

        self::assertSame(['1'], $index->names(MetadataSymbolKind::SerializerGroup));
    }

    public function testOverlaysKeepTheirSavedSourcePosition(): void
    {
        $index = new MetadataSourceIndex();
        $index->replace(
            $this->facts('file:///first.php', 'saved-first'),
            $this->facts('file:///second.php', 'saved-second'),
        );
        $index->overlay($this->facts('file:///first.php', 'overlay-first'));

        self::assertSame(['overlay-first', 'saved-second'], array_map(static fn (MetadataSourceSymbol $symbol): string => $symbol->name(), $index->symbols(MetadataSymbolKind::SerializerGroup)));
    }

    private function facts(string $uri, string $name): MetadataSourceFacts
    {
        $range = new Range(new Position(0, 0), new Position(0, 1));

        return new MetadataSourceFacts($uri, [new MetadataSourceSymbol(MetadataSymbolKind::SerializerGroup, $name, $uri, $range, true)]);
    }
}
