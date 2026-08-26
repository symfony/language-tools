<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Twig\LiveComponentEvent;
use Symfony\Lsp\Feature\Twig\TwigComponent;
use Symfony\Lsp\Feature\Twig\TwigComponentActionReference;
use Symfony\Lsp\Feature\Twig\TwigComponentIndex;
use Symfony\Lsp\Feature\Twig\TwigComponentReference;
use Symfony\Lsp\Feature\Twig\TwigComponentSourceFacts;

final class TwigComponentIndexTest extends TestCase
{
    public function testInvalidatesAllCachedSourceLookups(): void
    {
        $index = new TwigComponentIndex();
        [$firstFacts, $firstComponent, $firstReference, $firstActionReference, $firstEvent] = $this->facts('First');
        $index->replace($firstFacts);

        self::assertSame([$firstComponent], $index->components());
        self::assertSame([$firstComponent], $index->declarations('First'));
        self::assertSame($firstComponent, $index->get('First'));
        self::assertSame([$firstReference], $index->references('First'));
        self::assertSame([$firstActionReference], $index->actionReferences('First', 'save'));
        self::assertSame([$firstEvent], $index->events('first:changed'));
        self::assertSame(['first:changed'], $index->eventNames());
        self::assertTrue($index->isComplete());

        [$secondFacts, $secondComponent, $secondReference, $secondActionReference, $secondEvent] = $this->facts('Second');
        $index->replaceSource($secondFacts);

        self::assertSame([$secondComponent], $index->components());
        self::assertSame([], $index->declarations('First'));
        self::assertSame([$secondComponent], $index->declarations('Second'));
        self::assertNull($index->get('First'));
        self::assertSame($secondComponent, $index->get('Second'));
        self::assertSame([], $index->references('First'));
        self::assertSame([$secondReference], $index->references('Second'));
        self::assertSame([], $index->actionReferences('First', 'save'));
        self::assertSame([$secondActionReference], $index->actionReferences('Second', 'save'));
        self::assertSame([], $index->events('first:changed'));
        self::assertSame([$secondEvent], $index->events('second:changed'));
        self::assertSame(['second:changed'], $index->eventNames());
    }

    public function testPreservesNumericStringEventNames(): void
    {
        $event = new LiveComponentEvent('1', 'file:///component.php', $this->range(), true);
        $index = new TwigComponentIndex();
        $index->replace(new TwigComponentSourceFacts('file:///component.php', [], [], [], [$event]));

        self::assertSame(['1'], $index->eventNames());
    }

    public function testKeepsRuntimeCaseInsensitiveLookups(): void
    {
        $reference = new TwigComponentReference('UX:Icon', 'file:///templates/page.html.twig', $this->range());
        $index = new TwigComponentIndex();
        $index->replace(new TwigComponentSourceFacts('file:///templates/page.html.twig', [], [$reference]));
        $runtimeComponent = new TwigComponent('Ux:Icon', 'file:///vendor/ux-icon', $this->range());
        $index->replaceRuntime(true, ['ux:icon'], 'components', ['ux:icon'], [$runtimeComponent]);

        self::assertSame($runtimeComponent, $index->get('ux:icon'));
        self::assertSame([$reference], $index->references('ux:icon'));
    }

    /** @return array{TwigComponentSourceFacts, TwigComponent, TwigComponentReference, TwigComponentActionReference, LiveComponentEvent} */
    private function facts(string $name): array
    {
        $uri = 'file:///src/Twig/Components/Component.php';
        $component = new TwigComponent($name, $uri, $this->range(), 'App\\Twig\\Components\\'.$name);
        $reference = new TwigComponentReference($name, $uri, $this->range());
        $actionReference = new TwigComponentActionReference($name, 'save', $uri, $this->range());
        $event = new LiveComponentEvent(strtolower($name).':changed', $uri, $this->range(), true, $name, 'save');

        return [new TwigComponentSourceFacts($uri, [$component], [$reference], [$actionReference], [$event]), $component, $reference, $actionReference, $event];
    }

    private function range(): Range
    {
        return new Range(new Position(0, 0), new Position(0, 1));
    }
}
