<?php

namespace Symfony\Lsp\Tests\Feature\Event;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Event\EventSourceFacts;
use Symfony\Lsp\Feature\Event\EventSourceIndex;
use Symfony\Lsp\Feature\Event\EventSourceSymbol;

final class EventSourceIndexTest extends TestCase
{
    public function testInvalidatesCachedSymbols(): void
    {
        $index = new EventSourceIndex();
        $first = $this->facts('FirstEvent');
        $index->replace($first);

        self::assertSame([$first->symbols[0]], $index->symbols('\\App\\FirstEvent'));

        $second = $this->facts('SecondEvent');
        $index->replaceSource($second);
        $index->removeSource($first->uri);

        self::assertSame([], $index->symbols('App\\FirstEvent'));
        self::assertSame([$second->symbols[0]], $index->symbols('\\App\\SecondEvent'));
    }

    private function facts(string $name): EventSourceFacts
    {
        $uri = 'file:///src/'.$name.'.php';
        $range = new Range(new Position(0, 0), new Position(0, 1));

        return new EventSourceFacts($uri, [new EventSourceSymbol('App\\'.$name, $uri, $range, true)]);
    }
}
