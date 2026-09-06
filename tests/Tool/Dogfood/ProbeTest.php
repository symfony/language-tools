<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\Dogfood\Probe;

final class ProbeTest extends TestCase
{
    public function testIgnoresLinksThatDoNotCoverTheProbe(): void
    {
        self::assertSame(0, $this->probe(3, 20)->countCoveringLinks([
            $this->link(2, 20, 2, 40),
            $this->link(3, 0, 3, 19),
            $this->link(4, 0, 4, 40),
        ]));
    }

    public function testCountsOnlyTheLinksCoveringTheProbe(): void
    {
        self::assertSame(2, $this->probe(3, 20)->countCoveringLinks([
            $this->link(3, 0, 3, 40),
            $this->link(1, 0, 8, 0),
            $this->link(3, 21, 3, 40),
        ]));
    }

    public function testTreatsTheRangeStartAsInclusiveAndTheEndAsExclusive(): void
    {
        self::assertSame(1, $this->probe(3, 20)->countCoveringLinks([$this->link(3, 20, 3, 21)]));
        self::assertSame(0, $this->probe(3, 20)->countCoveringLinks([$this->link(3, 10, 3, 20)]));
        self::assertSame(1, $this->probe(3, 20)->countCoveringLinks([$this->link(3, 20, 4, 0)]));
        self::assertSame(0, $this->probe(3, 20)->countCoveringLinks([$this->link(2, 0, 3, 0)]));
    }

    public function testIgnoresResultsThatAreNotLinksWithARange(): void
    {
        $probe = $this->probe(3, 20);

        self::assertSame(0, $probe->countCoveringLinks(null));
        self::assertSame(0, $probe->countCoveringLinks('links'));
        self::assertSame(0, $probe->countCoveringLinks(['link' => $this->link(3, 0, 3, 40)]));
        self::assertSame(0, $probe->countCoveringLinks([$this->link(-1, 0, 4, 0)]));
        self::assertSame(0, $probe->countCoveringLinks([$this->link(3, -1, 3, 40)]));
        self::assertSame(0, $probe->countCoveringLinks(['target' => 'file:///app/config/services.yaml']));
        self::assertSame(0, $probe->countCoveringLinks([['target' => 'file:///app/config/services.yaml']]));
        self::assertSame(0, $probe->countCoveringLinks([['range' => ['start' => ['line' => 3], 'end' => ['line' => 3, 'character' => 40]]]]));
        self::assertSame(0, $probe->countCoveringLinks([['range' => ['start' => ['line' => '3', 'character' => '0'], 'end' => ['line' => 3, 'character' => 40]]]]));
        self::assertSame(0, $probe->countCoveringLinks([['range' => ['start' => ['line' => 3, 'character' => 0]]]]));
    }

    private function probe(int $line, int $character): Probe
    {
        return new Probe('import.yaml', '/app/config/services.yaml', '', 'services/*.yaml', $line, $character);
    }

    /** @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, target: string} */
    private function link(int $startLine, int $startCharacter, int $endLine, int $endCharacter): array
    {
        return [
            'range' => [
                'start' => ['line' => $startLine, 'character' => $startCharacter],
                'end' => ['line' => $endLine, 'character' => $endCharacter],
            ],
            'target' => 'file:///app/config/services.yaml',
        ];
    }
}
