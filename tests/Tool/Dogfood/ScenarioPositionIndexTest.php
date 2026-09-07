<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Tools\Dogfood\ScenarioPositionIndex;

final class ScenarioPositionIndexTest extends TestCase
{
    public function testMatchesTheScenarioCursorInsideACandidateRange(): void
    {
        $index = new ScenarioPositionIndex([['id' => 'route.reference', 'file' => 'src/A.php', 'position' => new Position(4, 12)]]);

        self::assertSame('route.reference', $index->match('src/A.php', new Range(new Position(4, 8), new Position(4, 20))));
        self::assertSame('route.reference', $index->match('src/A.php', new Range(new Position(4, 12), new Position(4, 12))));
        self::assertSame('route.reference', $index->match('src/A.php', new Range(new Position(3, 0), new Position(5, 0))));
        self::assertNull($index->match('src/A.php', new Range(new Position(4, 13), new Position(4, 20))));
        self::assertNull($index->match('src/A.php', new Range(new Position(4, 0), new Position(4, 11))));
        self::assertNull($index->match('src/B.php', new Range(new Position(4, 8), new Position(4, 20))));
    }

    public function testMatchesTheFirstScenarioOfTheFileInSourceOrder(): void
    {
        $index = new ScenarioPositionIndex([
            ['id' => 'later', 'file' => 'src/A.php', 'position' => new Position(4, 12)],
            ['id' => 'earlier', 'file' => 'src/A.php', 'position' => new Position(4, 9)],
        ]);

        self::assertSame('earlier', $index->match('src/A.php', new Range(new Position(4, 8), new Position(4, 20))));
        self::assertSame('later', $index->match('src/A.php', new Range(new Position(4, 10), new Position(4, 20))));
    }

    public function testListsScenarioIdentifiersOnce(): void
    {
        $index = new ScenarioPositionIndex([
            ['id' => 'twig.path', 'file' => 'templates/a.twig', 'position' => new Position(0, 0)],
            ['id' => 'php.route', 'file' => 'src/A.php', 'position' => new Position(0, 0)],
            ['id' => 'twig.path', 'file' => 'templates/b.twig', 'position' => new Position(0, 0)],
        ]);

        self::assertSame(['php.route', 'twig.path'], $index->ids());
    }
}
