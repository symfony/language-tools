<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Twig\TwigCallableDeclaration;
use Symfony\Lsp\Feature\Twig\TwigCallableIndex;
use Symfony\Lsp\Feature\Twig\TwigCallableKind;
use Symfony\Lsp\Feature\Twig\TwigCallableSourceFacts;
use Symfony\Lsp\Feature\Twig\TwigCallableUsage;

final class TwigCallableIndexTest extends TestCase
{
    public function testInvalidatesCachedNamesDeclarationsAndUsages(): void
    {
        $index = new TwigCallableIndex();
        [$firstFacts, $firstDeclaration, $firstUsage] = $this->facts('first');
        $index->replace($firstFacts);

        self::assertSame(['first'], $index->names(TwigCallableKind::Function));
        self::assertSame([$firstDeclaration], $index->declarations(TwigCallableKind::Function, 'first'));
        self::assertSame([$firstDeclaration], $index->declarationsForCallable('app\\extension', 'FIRST'));
        self::assertTrue($index->hasCallableDeclarations());
        self::assertSame($firstDeclaration, $index->declarationAt($firstDeclaration->uri, new Position(0, 0)));
        self::assertSame($firstDeclaration, $index->declarationAt($firstDeclaration->uri, new Position(0, 1)));
        self::assertNull($index->declarationAt($firstDeclaration->uri, new Position(0, 2)));
        self::assertNull($index->declarationAt('file:///src/Other.php', new Position(0, 0)));
        self::assertSame([$firstUsage], $index->usages(TwigCallableKind::Function, 'first'));

        [$secondFacts, $secondDeclaration, $secondUsage] = $this->facts('second');
        $index->replaceSource($secondFacts);

        self::assertSame(['second'], $index->names(TwigCallableKind::Function));
        self::assertSame([], $index->declarations(TwigCallableKind::Function, 'first'));
        self::assertSame([], $index->declarationsForCallable('App\\Extension', 'first'));
        self::assertSame([$secondDeclaration], $index->declarations(TwigCallableKind::Function, 'second'));
        self::assertSame([$secondDeclaration], $index->declarationsForCallable('App\\Extension', 'second'));
        self::assertSame($secondDeclaration, $index->declarationAt($secondDeclaration->uri, new Position(0, 0)));
        self::assertSame([], $index->usages(TwigCallableKind::Function, 'first'));
        self::assertSame([$secondUsage], $index->usages(TwigCallableKind::Function, 'second'));
    }

    public function testSortsExactLookupResultsByLocation(): void
    {
        $laterDeclaration = new TwigCallableDeclaration(TwigCallableKind::Filter, 'format', 'file:///b.php', $this->range(2, 3));
        $earlierDeclaration = new TwigCallableDeclaration(TwigCallableKind::Filter, 'format', 'file:///a.php', $this->range(4, 5));
        $laterUsage = new TwigCallableUsage(TwigCallableKind::Filter, 'format', 'file:///template.html.twig', $this->range(8, 9));
        $earlierUsage = new TwigCallableUsage(TwigCallableKind::Filter, 'format', 'file:///template.html.twig', $this->range(1, 2));
        $index = new TwigCallableIndex();
        $index->replace(new TwigCallableSourceFacts('file:///source', [$laterDeclaration, $earlierDeclaration], [$laterUsage, $earlierUsage]));

        self::assertSame([$earlierDeclaration, $laterDeclaration], $index->declarations(TwigCallableKind::Filter, 'format'));
        self::assertSame([$earlierUsage, $laterUsage], $index->usages(TwigCallableKind::Filter, 'format'));
    }

    /** @return array{TwigCallableSourceFacts, TwigCallableDeclaration, TwigCallableUsage} */
    private function facts(string $name): array
    {
        $uri = 'file:///src/TwigExtension.php';
        $declaration = new TwigCallableDeclaration(TwigCallableKind::Function, $name, $uri, $this->range(0, 0), 'App\\Extension', $name);
        $usage = new TwigCallableUsage(TwigCallableKind::Function, $name, 'file:///templates/page.html.twig', $this->range(0, 0));

        return [new TwigCallableSourceFacts($uri, [$declaration], [$usage]), $declaration, $usage];
    }

    private function range(int $line, int $character): Range
    {
        return new Range(new Position($line, $character), new Position($line, $character + 1));
    }
}
