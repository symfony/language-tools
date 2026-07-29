<?php

namespace Symfony\Lsp\Protocol;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;

/**
 * @implements \IteratorAggregate<int, string>
 */
final class ContentLengthReadableStream implements ReadableStream, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;

    public function __construct(
        private readonly ReadableStream $stream,
        private readonly MessageReaderInterface $reader,
    ) {
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        $message = $this->reader->read($cancellation);

        return null === $message ? null : $message."\n";
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    public function close(): void
    {
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
