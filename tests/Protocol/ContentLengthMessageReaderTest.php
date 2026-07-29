<?php

namespace Symfony\Lsp\Tests\Protocol;

use Amp\ByteStream\ReadableBuffer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Protocol\ContentLengthMessageReader;
use Symfony\Lsp\Protocol\Exception\FramingException;

final class ContentLengthMessageReaderTest extends TestCase
{
    public function testReadsConsecutiveMessagesAndHeadersCaseInsensitively(): void
    {
        $stream = new ReadableBuffer(
            "content-type: application/vscode-jsonrpc; charset=utf-8\r\n".
            "content-length: 2\r\n\r\n{}".
            "Content-Length: 4\r\n\r\nnull"
        );
        $reader = new ContentLengthMessageReader($stream);

        self::assertSame('{}', $reader->read());
        self::assertSame('null', $reader->read());
        self::assertNull($reader->read());
    }

    #[DataProvider('invalidFrameProvider')]
    public function testRejectsInvalidFrames(string $frame, string $message): void
    {
        $reader = new ContentLengthMessageReader(new ReadableBuffer($frame));

        $this->expectException(FramingException::class);
        $this->expectExceptionMessage($message);

        $reader->read();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFrameProvider(): iterable
    {
        yield 'missing length' => ["Content-Type: application/json\r\n\r\n{}", 'missing'];
        yield 'duplicate length' => ["Content-Length: 2\r\nContent-Length: 2\r\n\r\n{}", 'duplicate'];
        yield 'negative length' => ["Content-Length: -1\r\n\r\n", 'invalid'];
        yield 'line-feed headers' => ["Content-Length: 2\n\n{}", 'headers'];
        yield 'truncated headers' => ["Content-Length: 2\r\n", 'headers'];
        yield 'truncated body' => ["Content-Length: 3\r\n\r\n{}", 'body'];
    }

    public function testEnforcesHeaderAndMessageLimits(): void
    {
        $reader = new ContentLengthMessageReader(
            new ReadableBuffer("X-Header: long\r\nContent-Length: 2\r\n\r\n{}"),
            maximumHeaderBytes: 8,
        );

        $this->expectException(FramingException::class);
        $this->expectExceptionMessage('headers exceed');

        $reader->read();
    }

    public function testRejectsOversizedMessageBeforeReadingItsBody(): void
    {
        $reader = new ContentLengthMessageReader(
            new ReadableBuffer("Content-Length: 3\r\n\r\n{}"),
            maximumMessageBytes: 2,
        );

        $this->expectException(FramingException::class);
        $this->expectExceptionMessage('body exceeds');

        $reader->read();
    }
}
