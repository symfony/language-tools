<?php

namespace Symfony\Lsp\Tests\Support;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\WritableStream;

final class CapturingWritableStream implements WritableStream
{
    private string $contents = '';
    private bool $closed = false;

    public function write(string $bytes): void
    {
        if ($this->closed) {
            throw new ClosedException('The stream is closed.');
        }

        $this->contents .= $bytes;
    }

    public function end(): void
    {
        $this->close();
    }

    public function isWritable(): bool
    {
        return !$this->closed;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        if ($this->closed) {
            $onClose();
        }
    }

    public function contents(): string
    {
        return $this->contents;
    }
}
