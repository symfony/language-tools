<?php

namespace Symfony\Lsp\Tests\Protocol;

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
}
