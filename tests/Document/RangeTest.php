<?php

namespace Symfony\Lsp\Tests\Document;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;

final class RangeTest extends TestCase
{
    #[DataProvider('positionProvider')]
    public function testReportsWhetherAPositionIsInsideTheRange(Position $position, bool $expected): void
    {
        $range = new Range(new Position(1, 4), new Position(3, 2));

        self::assertSame($expected, $range->containsPosition($position));
    }

    /** @return iterable<string, array{Position, bool}> */
    public static function positionProvider(): iterable
    {
        yield 'before the first line' => [new Position(0, 9), false];
        yield 'before the start character' => [new Position(1, 3), false];
        yield 'on the start' => [new Position(1, 4), true];
        yield 'after the start character' => [new Position(1, 5), true];
        yield 'on an inner line' => [new Position(2, 0), true];
        yield 'before the end character' => [new Position(3, 1), true];
        yield 'on the end' => [new Position(3, 2), true];
        yield 'after the end character' => [new Position(3, 3), false];
        yield 'after the last line' => [new Position(4, 0), false];
    }

    public function testASingleCharacterRangeContainsBothOfItsBounds(): void
    {
        $range = new Range(new Position(2, 6), new Position(2, 6));

        self::assertTrue($range->containsPosition(new Position(2, 6)));
        self::assertFalse($range->containsPosition(new Position(2, 5)));
        self::assertFalse($range->containsPosition(new Position(2, 7)));
    }
}
