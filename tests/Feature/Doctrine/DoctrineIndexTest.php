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
        $index->removeSource($firstFacts->uri);

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

    public function testOpeningADocumentDoesNotChangeWhichDuplicateEntityWins(): void
    {
        $index = new DoctrineIndex();
        $first = new DoctrineSourceFacts('file:///src/First.php', [new DoctrineEntity('App\\Duplicate', 'file:///src/First.php', $this->range(), null, [])], [], []);
        $second = new DoctrineSourceFacts('file:///src/Second.php', [new DoctrineEntity('App\\Duplicate', 'file:///src/Second.php', $this->range(), null, [])], [], []);
        $index->replace($first, $second);

        $index->overlay($first);

        self::assertSame('file:///src/First.php', $index->entity('App\\Duplicate')?->uri);
        self::assertSame(['file:///src/First.php'], array_map(static fn (DoctrineEntity $entity): string => $entity->uri, $index->entities()));
    }

    public function testLooksClassesUpRegardlessOfCaseAndLeadingBackslash(): void
    {
        $index = new DoctrineIndex();
        [$facts, $entity, $repository] = $this->facts('First');
        $index->replace($facts);

        self::assertSame($entity, $index->entity('\\app\\FIRST'));
        self::assertSame($repository, $index->repository('\\app\\firstREPOSITORY'));
        self::assertSame($entity, $index->entityForRepository('\\APP\\FirstRepository'));
    }

    public function testRelatesFieldsOfOneEntityWrittenWithDifferentOwnerSpellings(): void
    {
        $index = new DoctrineIndex();
        $entity = new DoctrineEntity('App\\Article', 'file:///src/Article.php', $this->range(), null, []);
        $declaration = new DoctrineSourceSymbol(DoctrineSymbolKind::Field, 'title', 'App\\Article', 'file:///src/Article.php', $this->range(), true);
        $reference = new DoctrineSourceSymbol(DoctrineSymbolKind::Field, 'title', '\\app\\article', 'file:///src/ArticleRepository.php', $this->range(), false);
        $index->replace(new DoctrineSourceFacts('file:///src/Article.php', [$entity], [], [$declaration, $reference]));

        self::assertSame([$declaration, $reference], $index->relatedSymbols($declaration));
    }

    /** @return array{DoctrineSourceFacts, DoctrineEntity, DoctrineRepository, DoctrineSourceSymbol} */
    private function facts(string $name): array
    {
        $entity = $this->entity($name);
        $repository = new DoctrineRepository('App\\'.$name.'Repository', 'App\\'.$name, $entity->uri, $this->range());
        $symbol = new DoctrineSourceSymbol(DoctrineSymbolKind::Entity, 'App\\'.$name, null, $entity->uri, $this->range(), true);

        return [new DoctrineSourceFacts($entity->uri, [$entity], [$repository], [$symbol]), $entity, $repository, $symbol];
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
