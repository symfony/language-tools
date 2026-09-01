<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Index\RangedSourceSymbolInterface;
use Symfony\Lsp\Index\SourceDocument;

final class PositionedSourceSymbolResolverTest extends TestCase
{
    public function testResolvesTheSymbolContainingThePositionInclusively(): void
    {
        $positions = new PositionConverter();
        $document = new SourceDocument('file:///workspace/example.php', 'php', 'é first second');
        $firstOffset = (int) strpos($document->text, 'first');
        $secondOffset = (int) strpos($document->text, 'second');
        $first = new TestRangedSourceSymbol($positions->toRange($document->text, $firstOffset, \strlen('first')));
        $second = new TestRangedSourceSymbol($positions->toRange($document->text, $secondOffset, \strlen('second')));
        $position = $positions->toPosition($document->text, $secondOffset + \strlen('second'));

        self::assertSame($second, (new PositionedSourceSymbolResolver($positions))->resolve($document, $position, [$first, $second]));
    }
}

final class TestRangedSourceSymbol implements RangedSourceSymbolInterface
{
    public function __construct(public readonly Range $range)
    {
    }
}
