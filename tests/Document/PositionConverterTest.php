<?php

namespace Symfony\Lsp\Tests\Document;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class PositionConverterTest extends TestCase
{
    #[DataProvider('positionProvider')]
    public function testConvertsUtf16PositionsToByteOffsets(Position $position, int $expected): void
    {
        self::assertSame($expected, (new PositionConverter())->toByteOffset("a😀b\néx", $position));
    }

    /**
     * @return iterable<string, array{Position, int}>
     */
    public static function positionProvider(): iterable
    {
        yield 'ASCII character' => [new Position(0, 1), 1];
        yield 'before astral character' => [new Position(0, 1), 1];
        yield 'inside astral character clamps before it' => [new Position(0, 2), 1];
        yield 'after astral character' => [new Position(0, 3), 5];
        yield 'second line' => [new Position(1, 1), 9];
        yield 'past document' => [new Position(9, 0), 10];
    }

    public function testConvertsByteOffsetsToUtf16Positions(): void
    {
        $converter = new PositionConverter();

        $position = $converter->toPosition("a😀b\néx", 5);

        self::assertSame(0, $position->line());
        self::assertSame(3, $position->character());
    }

    public function testNegotiatesUtf8AndUtf32Positions(): void
    {
        $converter = new PositionConverter();

        self::assertSame('utf-8', $converter->negotiate(['utf-8', 'utf-16']));
        self::assertSame(5, $converter->toPosition('a😀b', 5)->character());
        self::assertSame(5, $converter->toByteOffset('a😀b', new Position(0, 5)));

        self::assertSame('utf-32', $converter->negotiate(['unsupported', 'utf-32']));
        self::assertSame(2, $converter->toPosition('a😀b', 5)->character());
        self::assertSame(5, $converter->toByteOffset('a😀b', new Position(0, 2)));
    }

    public function testAppliesIncrementalChangesWithUtf16Ranges(): void
    {
        $converter = new PositionConverter();

        self::assertSame('aXb', $converter->applyChange(
            'a😀b',
            new Range(new Position(0, 1), new Position(0, 3)),
            'X',
        ));
    }
}
