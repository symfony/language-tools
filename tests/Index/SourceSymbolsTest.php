<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\LocatedSourceSymbolInterface;
use Symfony\Lsp\Index\SourceSymbols;

final class SourceSymbolsTest extends TestCase
{
    public function testKeepsTheLastSymbolOfEveryLocation(): void
    {
        $first = new LocatedSymbol('file:///a.php', 1, 0, 1, 4, 'role');
        $duplicate = new LocatedSymbol('file:///a.php', 1, 0, 1, 4, 'role');
        $other = new LocatedSymbol('file:///a.php', 2, 0, 2, 4, 'role');

        self::assertSame([$duplicate, $other], SourceSymbols::unique([$first, $duplicate, $other]));
    }

    public function testKeepsSymbolsApartByUriAndEndPosition(): void
    {
        $short = new LocatedSymbol('file:///a.php', 1, 0, 1, 4, 'role');
        $long = new LocatedSymbol('file:///a.php', 1, 0, 1, 8, 'role');
        $elsewhere = new LocatedSymbol('file:///b.php', 1, 0, 1, 4, 'role');

        self::assertSame([$short, $long, $elsewhere], SourceSymbols::unique([$short, $long, $elsewhere]));
    }

    public function testKeepsSymbolsApartByDiscriminator(): void
    {
        $role = new LocatedSymbol('file:///a.php', 1, 0, 1, 4, 'role');
        $firewall = new LocatedSymbol('file:///a.php', 1, 0, 1, 4, 'firewall');
        $duplicateRole = new LocatedSymbol('file:///a.php', 1, 0, 1, 4, 'role');

        self::assertSame(
            [$duplicateRole, $firewall],
            SourceSymbols::unique([$role, $firewall, $duplicateRole], static fn (LocatedSymbol $symbol): string => $symbol->kind),
        );
        self::assertSame([$duplicateRole], SourceSymbols::unique([$role, $firewall, $duplicateRole]));
    }

    public function testKeepsAnEmptyListEmpty(): void
    {
        self::assertSame([], SourceSymbols::unique([]));
    }
}

final class LocatedSymbol implements LocatedSourceSymbolInterface
{
    public readonly Range $range;

    public function __construct(
        public readonly string $uri,
        int $startLine,
        int $startCharacter,
        int $endLine,
        int $endCharacter,
        public readonly string $kind,
    ) {
        $this->range = new Range(new Position($startLine, $startCharacter), new Position($endLine, $endCharacter));
    }
}
