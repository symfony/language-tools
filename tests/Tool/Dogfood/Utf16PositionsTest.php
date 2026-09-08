<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\Dogfood\ScenarioStepException;
use Symfony\Lsp\Tools\Dogfood\Utf16Positions;

final class Utf16PositionsTest extends TestCase
{
    private Utf16Positions $positions;

    protected function setUp(): void
    {
        $this->positions = new Utf16Positions();
    }

    public function testConvertsPositionsToByteOffsets(): void
    {
        $text = "héllo\r\n🐘 world\n";

        self::assertSame(0, $this->positions->byteOffset($text, ['line' => 0, 'character' => 0]));
        self::assertSame(6, $this->positions->byteOffset($text, ['line' => 0, 'character' => 5]));
        self::assertSame(12, $this->positions->byteOffset($text, ['line' => 1, 'character' => 2]));
    }

    public function testRejectsPositionsBeyondTheLine(): void
    {
        $this->expectExceptionObject(new ScenarioStepException('Character 9 is beyond the 5 UTF-16 code units of line 0.'));
        $this->positions->byteOffset("héllo\nworld\n", ['line' => 0, 'character' => 9]);
    }

    public function testRejectsPositionsBeyondTheDocument(): void
    {
        $this->expectExceptionObject(new ScenarioStepException('Line 4 is outside the 3 line document.'));
        $this->positions->byteOffset("héllo\nworld\n", ['line' => 4, 'character' => 0]);
    }

    public function testRejectsPositionsInsideACharacter(): void
    {
        $this->expectExceptionObject(new ScenarioStepException('Character 1 of line 0 splits a UTF-16 surrogate pair.'));
        $this->positions->byteOffset("🐘 world\n", ['line' => 0, 'character' => 1]);
    }

    public function testRejectsMalformedPositions(): void
    {
        $this->expectExceptionMessage('is not a pair of non-negative integers');
        $this->positions->byteOffset("hello\n", ['line' => 0, 'character' => -1]);
    }
}
