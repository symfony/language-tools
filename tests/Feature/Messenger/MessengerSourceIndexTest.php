<?php

namespace Symfony\Lsp\Tests\Feature\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Messenger\MessengerSourceFacts;
use Symfony\Lsp\Feature\Messenger\MessengerSourceIndex;
use Symfony\Lsp\Feature\Messenger\MessengerSourceSymbol;
use Symfony\Lsp\Feature\Messenger\MessengerSymbolKind;

final class MessengerSourceIndexTest extends TestCase
{
    public function testInvalidatesCachedSymbolsAndAncestors(): void
    {
        $index = new MessengerSourceIndex();
        $first = $this->facts('FirstMessage', ['App\\ParentMessage']);
        $index->replace($first);

        self::assertSame([$first->symbols[0]], $index->symbols(MessengerSymbolKind::Message, 'App\\FirstMessage'));
        self::assertSame(['App\\ParentMessage'], $index->ancestors('\\App\\FirstMessage'));

        $second = $this->facts('SecondMessage', ['App\\IntermediateMessage']);
        $parent = $this->facts('IntermediateMessage', ['App\\RootMessage']);
        $index->replaceSource($second);
        $index->replaceSource($parent);
        $index->removeSource($first->uri);

        self::assertSame([], $index->symbols(MessengerSymbolKind::Message, 'App\\FirstMessage'));
        self::assertSame([$second->symbols[0]], $index->symbols(MessengerSymbolKind::Message, 'App\\SecondMessage'));
        self::assertSame(['App\\IntermediateMessage', 'App\\RootMessage'], $index->ancestors('App\\SecondMessage'));
    }

    /** @param list<string> $parents */
    private function facts(string $name, array $parents): MessengerSourceFacts
    {
        $className = 'App\\'.$name;
        $uri = 'file:///src/'.$name.'.php';
        $range = new Range(new Position(0, 0), new Position(0, 1));

        return new MessengerSourceFacts($uri, [new MessengerSourceSymbol(MessengerSymbolKind::Message, $className, $uri, $range, true)], [$className => $parents]);
    }
}
