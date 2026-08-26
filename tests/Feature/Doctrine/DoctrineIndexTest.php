<?php

namespace Symfony\Lsp\Tests\Feature\Doctrine;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Doctrine\DoctrineEntity;
use Symfony\Lsp\Feature\Doctrine\DoctrineIndex;
use Symfony\Lsp\Feature\Doctrine\DoctrineRepository;
use Symfony\Lsp\Feature\Doctrine\DoctrineSourceFacts;
use Symfony\Lsp\Feature\Doctrine\DoctrineSourceSymbol;
use Symfony\Lsp\Feature\Doctrine\DoctrineSymbolKind;

final class DoctrineIndexTest extends TestCase
{
    public function testInvalidatesCachedSourceLookups(): void
    {
        $index = new DoctrineIndex();
        [$firstFacts, $firstEntity, $firstRepository, $firstSymbol] = $this->facts('First');
        $index->replace($firstFacts);

        self::assertSame($firstEntity, $index->entity('App\\First'));
        self::assertSame([$firstEntity], $index->entities());
        self::assertSame($firstRepository, $index->repository('App\\FirstRepository'));
        self::assertSame($firstEntity, $index->entityForRepository('App\\FirstRepository'));
        self::assertSame([$firstSymbol], $index->relatedSymbols($firstSymbol));

        [$secondFacts, $secondEntity, $secondRepository, $secondSymbol] = $this->facts('Second');
        $index->replaceSource($secondFacts);
        $index->removeSource($firstFacts->uri());

        self::assertNull($index->entity('App\\First'));
        self::assertSame($secondEntity, $index->entity('App\\Second'));
        self::assertSame([$secondEntity], $index->entities());
        self::assertNull($index->repository('App\\FirstRepository'));
        self::assertSame($secondRepository, $index->repository('App\\SecondRepository'));
        self::assertSame($secondEntity, $index->entityForRepository('App\\SecondRepository'));
        self::assertSame([], $index->relatedSymbols($firstSymbol));
        self::assertSame([$secondSymbol], $index->relatedSymbols($secondSymbol));
    }

    public function testInvalidatesCachedRuntimeEntities(): void
    {
        $index = new DoctrineIndex();
        $first = $this->entity('RuntimeFirst');
        $index->replaceRuntime($first);
        self::assertSame($first, $index->entity('App\\RuntimeFirst'));

        $second = $this->entity('RuntimeSecond');
        $index->replaceRuntime($second);

        self::assertNull($index->entity('App\\RuntimeFirst'));
        self::assertSame($second, $index->entity('App\\RuntimeSecond'));
    }

    /** @return array{DoctrineSourceFacts, DoctrineEntity, DoctrineRepository, DoctrineSourceSymbol} */
    private function facts(string $name): array
    {
        $entity = $this->entity($name);
        $repository = new DoctrineRepository('App\\'.$name.'Repository', 'App\\'.$name, $entity->uri(), $this->range());
        $symbol = new DoctrineSourceSymbol(DoctrineSymbolKind::Entity, 'App\\'.$name, null, $entity->uri(), $this->range(), true);

        return [new DoctrineSourceFacts($entity->uri(), [$entity], [$repository], [$symbol]), $entity, $repository, $symbol];
    }

    private function entity(string $name): DoctrineEntity
    {
        return new DoctrineEntity('App\\'.$name, 'file:///src/'.$name.'.php', $this->range(), 'App\\'.$name.'Repository', []);
    }

    private function range(): Range
    {
        return new Range(new Position(0, 0), new Position(0, 1));
    }
}
