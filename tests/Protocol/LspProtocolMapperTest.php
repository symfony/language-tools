<?php

namespace Symfony\Lsp\Tests\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class LspProtocolMapperTest extends TestCase
{
    private LspProtocolMapper $mapper;
    private Range $range;

    protected function setUp(): void
    {
        $this->mapper = new LspProtocolMapper();
        $this->range = new Range(new Position(2, 3), new Position(4, 5));
    }

    public function testMapsRanges(): void
    {
        self::assertSame([
            'start' => ['line' => 2, 'character' => 3],
            'end' => ['line' => 4, 'character' => 5],
        ], $this->mapper->range($this->range));
    }

    public function testMapsLocations(): void
    {
        self::assertSame([
            'uri' => 'file:///workspace/config/services.yaml',
            'range' => [
                'start' => ['line' => 2, 'character' => 3],
                'end' => ['line' => 4, 'character' => 5],
            ],
        ], $this->mapper->location('file:///workspace/config/services.yaml', $this->range));
    }

    public function testMapsZeroRange(): void
    {
        self::assertSame([
            'start' => ['line' => 0, 'character' => 0],
            'end' => ['line' => 0, 'character' => 0],
        ], $this->mapper->zeroRange());
    }

    public function testMapsSymfonyDiagnostics(): void
    {
        self::assertSame([
            'range' => [
                'start' => ['line' => 2, 'character' => 3],
                'end' => ['line' => 4, 'character' => 5],
            ],
            'severity' => 2,
            'source' => 'symfony',
            'code' => 'config.deprecated_key',
            'message' => 'The configuration key is deprecated.',
        ], $this->mapper->diagnostic($this->range, 2, 'config.deprecated_key', 'The configuration key is deprecated.'));
    }

    public function testMapsMarkdownHovers(): void
    {
        self::assertSame([
            'contents' => ['kind' => 'markdown', 'value' => 'Rendered **documentation**'],
        ], $this->mapper->markdownHover('Rendered **documentation**'));
    }

    public function testMapsTextEdits(): void
    {
        self::assertSame([
            'range' => [
                'start' => ['line' => 2, 'character' => 3],
                'end' => ['line' => 4, 'character' => 5],
            ],
            'newText' => 'replacement',
        ], $this->mapper->textEdit($this->range, 'replacement'));
    }

    public function testMapsReferenceLenses(): void
    {
        $locations = [[
            'uri' => 'file:///workspace/src/Listener.php',
            'range' => [
                'start' => ['line' => 6, 'character' => 7],
                'end' => ['line' => 8, 'character' => 9],
            ],
        ]];

        self::assertSame([
            'range' => [
                'start' => ['line' => 2, 'character' => 3],
                'end' => ['line' => 4, 'character' => 5],
            ],
            'command' => [
                'title' => '1 event listener',
                'command' => 'editor.action.showReferences',
                'arguments' => ['file:///workspace/src/Event.php', ['line' => 2, 'character' => 3], $locations],
            ],
        ], $this->mapper->referenceLens($this->range, '1 event listener', 'file:///workspace/src/Event.php', $locations));
    }

    /** @param array<array-key, mixed> $protocolRange */
    #[DataProvider('protocolRangeProvider')]
    public function testComparesInternalRangesWithProtocolRanges(bool $expected, array $protocolRange): void
    {
        self::assertSame($expected, $this->mapper->sameRange($this->range, $protocolRange));
    }

    /** @return iterable<string, array{bool, array<array-key, mixed>}> */
    public static function protocolRangeProvider(): iterable
    {
        yield 'identical' => [true, ['start' => ['line' => 2, 'character' => 3], 'end' => ['line' => 4, 'character' => 5]]];
        yield 'different line' => [false, ['start' => ['line' => 1, 'character' => 3], 'end' => ['line' => 4, 'character' => 5]]];
        yield 'different character' => [false, ['start' => ['line' => 2, 'character' => 3], 'end' => ['line' => 4, 'character' => 6]]];
        yield 'missing end' => [false, ['start' => ['line' => 2, 'character' => 3]]];
        yield 'malformed positions' => [false, ['start' => 2, 'end' => 4]];
        yield 'string coordinates' => [false, ['start' => ['line' => '2', 'character' => '3'], 'end' => ['line' => '4', 'character' => '5']]];
    }
}
