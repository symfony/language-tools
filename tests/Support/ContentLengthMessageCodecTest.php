<?php

namespace Symfony\Lsp\Tests\Support;

use PHPUnit\Framework\TestCase;

final class ContentLengthMessageCodecTest extends TestCase
{
    public function testEncodesAndDecodesCompleteTranscripts(): void
    {
        $codec = new ContentLengthMessageCodec();
        $first = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ready' => true]];
        $second = ['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []];
        $secondJson = json_encode($second, \JSON_THROW_ON_ERROR);
        $transcript = $codec->encode($first)
            .'Content-Length: '.\strlen($secondJson)."\r\nContent-Type: application/vscode-jsonrpc; charset=utf-8\r\n\r\n".$secondJson;

        self::assertSame([$first, $second], $codec->decode($transcript));
    }

    public function testDecodesOnlyCompleteMessagesWhileOutputIsStillBeingWritten(): void
    {
        $codec = new ContentLengthMessageCodec();
        $first = ['jsonrpc' => '2.0', 'id' => 1, 'result' => null];
        $second = ['jsonrpc' => '2.0', 'id' => 2, 'result' => null];
        $transcript = $codec->encode($first).substr($codec->encode($second), 0, -2);

        self::assertSame([$first], $codec->decodeAvailable($transcript));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The Content-Length transcript ends with an incomplete message.');

        $codec->decode($transcript);
    }

    public function testReadsOneMessageFromAStream(): void
    {
        $codec = new ContentLengthMessageCodec();
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'result' => []];
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $codec->encode($message));
        rewind($stream);

        self::assertSame($message, $codec->read($stream));

        fclose($stream);
    }
}
