<?php

namespace Symfony\Lsp\Tests\Feature\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Security\SecuritySourceFacts;
use Symfony\Lsp\Feature\Security\SecuritySourceIndex;
use Symfony\Lsp\Feature\Security\SecuritySourceSymbol;
use Symfony\Lsp\Feature\Security\SecuritySymbolKind;

final class SecuritySourceIndexTest extends TestCase
{
    public function testOverlaysShadowSavedFactsAfterUnshadowedSources(): void
    {
        $index = new SecuritySourceIndex();
        $index->replace(
            $this->facts('file:///first.php'),
            $this->facts('file:///second.php'),
        );
        $index->overlay($this->facts('file:///first.php'));

        self::assertSame(['file:///second.php', 'file:///first.php'], array_map(static fn (SecuritySourceSymbol $symbol): string => $symbol->uri(), $index->symbols(SecuritySymbolKind::Role, 'ROLE_USER')));

        $index->removeOverlay('file:///first.php');

        self::assertSame(['file:///first.php', 'file:///second.php'], array_map(static fn (SecuritySourceSymbol $symbol): string => $symbol->uri(), $index->symbols(SecuritySymbolKind::Role, 'ROLE_USER')));
    }

    private function facts(string $uri): SecuritySourceFacts
    {
        $range = new Range(new Position(0, 0), new Position(0, 0));

        return new SecuritySourceFacts($uri, [new SecuritySourceSymbol(SecuritySymbolKind::Role, 'ROLE_USER', $uri, $range, false)]);
    }
}
