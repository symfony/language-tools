<?php

namespace Symfony\Lsp\Tests\Feature\Asset;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Asset\AssetSourceFacts;
use Symfony\Lsp\Feature\Asset\AssetSourceIndex;
use Symfony\Lsp\Feature\Asset\AssetSourceSymbol;
use Symfony\Lsp\Feature\Asset\AssetSymbolKind;

final class AssetSourceIndexTest extends TestCase
{
    public function testOverlaysShadowSavedFactsInTheirSourcePosition(): void
    {
        $index = new AssetSourceIndex();
        $index->replace(
            $this->facts('file:///first.php', 'saved-first'),
            $this->facts('file:///second.php', 'saved-second'),
        );
        $index->overlay($this->facts('file:///first.php', 'overlay-first'));

        self::assertSame(['overlay-first', 'saved-second'], array_map(static fn (AssetSourceSymbol $symbol): string => $symbol->name(), $index->symbols(AssetSymbolKind::Asset)));

        $index->removeOverlay('file:///first.php');

        self::assertSame(['saved-first', 'saved-second'], array_map(static fn (AssetSourceSymbol $symbol): string => $symbol->name(), $index->symbols(AssetSymbolKind::Asset)));
    }

    private function facts(string $uri, string $name): AssetSourceFacts
    {
        $range = new Range(new Position(0, 0), new Position(0, 0));

        return new AssetSourceFacts($uri, [new AssetSourceSymbol(AssetSymbolKind::Asset, $name, $uri, $range, false)]);
    }
}
