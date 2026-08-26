<?php

namespace Symfony\Lsp\Tests\Feature\Environment;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Environment\EnvironmentDeclaration;
use Symfony\Lsp\Feature\Environment\EnvironmentIndex;
use Symfony\Lsp\Feature\Environment\EnvironmentReference;
use Symfony\Lsp\Feature\Environment\EnvironmentSourceFacts;

final class EnvironmentIndexTest extends TestCase
{
    public function testInvalidatesCachedNamesDeclarationsAndReferences(): void
    {
        $index = new EnvironmentIndex();
        [$firstFacts, $firstDeclaration, $firstReference] = $this->facts('FIRST');
        $index->replace($firstFacts);

        self::assertSame(['FIRST'], $index->names());
        self::assertSame([$firstDeclaration], $index->declarations('FIRST'));
        self::assertSame([$firstReference], $index->references('FIRST'));

        [$secondFacts, $secondDeclaration, $secondReference] = $this->facts('SECOND');
        $index->replaceSource($secondFacts);
        $index->removeSource($firstFacts->uri());

        self::assertSame(['SECOND'], $index->names());
        self::assertSame([], $index->declarations('FIRST'));
        self::assertSame([$secondDeclaration], $index->declarations('SECOND'));
        self::assertSame([], $index->references('FIRST'));
        self::assertSame([$secondReference], $index->references('SECOND'));
    }

    /** @return array{EnvironmentSourceFacts, EnvironmentDeclaration, EnvironmentReference} */
    private function facts(string $name): array
    {
        $uri = 'file:///config/'.$name.'.env';
        $declaration = new EnvironmentDeclaration($name, $uri, $this->range(), false);
        $reference = new EnvironmentReference($name, $uri, $this->range(), []);

        return [new EnvironmentSourceFacts($uri, [$declaration], [$reference]), $declaration, $reference];
    }

    private function range(): Range
    {
        return new Range(new Position(0, 0), new Position(0, 1));
    }
}
