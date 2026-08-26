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
            $this->facts('file:///first.php', 'ROLE_USER'),
            $this->facts('file:///second.php', 'ROLE_USER'),
        );
        $index->overlay($this->facts('file:///first.php', 'ROLE_USER'));

        self::assertSame(['file:///second.php', 'file:///first.php'], array_map(static fn (SecuritySourceSymbol $symbol): string => $symbol->uri(), $index->symbols(SecuritySymbolKind::Role, 'ROLE_USER')));

        $index->removeOverlay('file:///first.php');

        self::assertSame(['file:///first.php', 'file:///second.php'], array_map(static fn (SecuritySourceSymbol $symbol): string => $symbol->uri(), $index->symbols(SecuritySymbolKind::Role, 'ROLE_USER')));
    }

    public function testInvalidatesCachedSymbolsAndNames(): void
    {
        $index = new SecuritySourceIndex();
        $first = $this->facts('file:///security.php', 'ROLE_FIRST', true);
        $index->replace($first);

        self::assertSame([$first->symbols()[0]], $index->symbols(SecuritySymbolKind::Role, 'ROLE_FIRST'));
        self::assertSame(['ROLE_FIRST'], $index->names(SecuritySymbolKind::Role));
        self::assertSame(['ROLE_FIRST'], $index->declarationNames(SecuritySymbolKind::Role));

        $second = $this->facts('file:///security.php', 'ROLE_SECOND');
        $index->replaceSource($second);

        self::assertSame([], $index->symbols(SecuritySymbolKind::Role, 'ROLE_FIRST'));
        self::assertSame([$second->symbols()[0]], $index->symbols(SecuritySymbolKind::Role, 'ROLE_SECOND'));
        self::assertSame(['ROLE_SECOND'], $index->names(SecuritySymbolKind::Role));
        self::assertSame([], $index->declarationNames(SecuritySymbolKind::Role));
    }

    private function facts(string $uri, string $name, bool $declaration = false): SecuritySourceFacts
    {
        $range = new Range(new Position(0, 0), new Position(0, 0));

        return new SecuritySourceFacts($uri, [new SecuritySourceSymbol(SecuritySymbolKind::Role, $name, $uri, $range, $declaration)]);
    }
}
