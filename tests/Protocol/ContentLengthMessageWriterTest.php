<?php

namespace Symfony\Lsp\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Protocol\ContentLengthMessageWriter;
use Symfony\Lsp\Tests\Support\CapturingWritableStream;

final class ContentLengthMessageWriterTest extends TestCase
{
    public function testWritesUtf8ByteLength(): void
    {
        $output = new CapturingWritableStream();
        $writer = new ContentLengthMessageWriter($output);

        $writer->write('"é"');

        self::assertSame("Content-Length: 4\r\n\r\n\"é\"", $output->contents());
    }

    public function testRejectsOversizedMessages(): void
    {
        $writer = new ContentLengthMessageWriter(new CapturingWritableStream(), 2);

        $this->expectException(\LengthException::class);

        $writer->write('abc');
    }
}
