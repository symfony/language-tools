<?php

namespace Symfony\Lsp\Protocol;

use Amp\ByteStream\WritableStream;

final class ContentLengthWritableStream implements WritableStream
{
    private string $buffer = '';

    public function __construct(
        private readonly WritableStream $stream,
        private readonly MessageWriterInterface $writer,
    ) {
    }

    public function write(string $bytes): void
    {
        $this->buffer .= $bytes;

        while (false !== $offset = strpos($this->buffer, "\n")) {
            $message = substr($this->buffer, 0, $offset);
            $this->buffer = substr($this->buffer, $offset + 1);
            if ('' !== $message) {
                $this->writer->write($message);
            }
        }
    }

    public function end(): void
    {
        if ('' !== $this->buffer) {
            $this->writer->write($this->buffer);
            $this->buffer = '';
        }

        $this->stream->end();
    }

    public function isWritable(): bool
    {
        return $this->stream->isWritable();
    }

    public function close(): void
    {
        $this->buffer = '';
        $this->stream->close();
    }

    public function isClosed(): bool
    {
        return $this->stream->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->stream->onClose($onClose);
    }
}
