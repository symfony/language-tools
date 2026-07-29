<?php

namespace Symfony\Lsp\Protocol;

use Amp\ByteStream\WritableStream;

final class ContentLengthMessageWriter implements MessageWriterInterface
{
    public function __construct(
        private readonly WritableStream $stream,
        private readonly int $maximumMessageBytes = 16777216,
    ) {
        if ($maximumMessageBytes < 1) {
            throw new \InvalidArgumentException('The message limit must be a positive integer.');
        }
    }

    public function write(string $message): void
    {
        $length = \strlen($message);
        if ($length > $this->maximumMessageBytes) {
            throw new \LengthException('The message body exceeds the configured limit.');
        }

        $this->stream->write("Content-Length: {$length}\r\n\r\n{$message}");
    }
}
