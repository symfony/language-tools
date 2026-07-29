<?php

namespace Symfony\Lsp\Protocol;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Symfony\Lsp\Protocol\Exception\FramingException;

final class ContentLengthMessageReader implements MessageReaderInterface
{
    private string $buffer = '';
    private bool $eof = false;

    public function __construct(
        private readonly ReadableStream $stream,
        private readonly int $maximumHeaderBytes = 8192,
        private readonly int $maximumMessageBytes = 16777216,
    ) {
        if ($maximumHeaderBytes < 1 || $maximumMessageBytes < 1) {
            throw new \InvalidArgumentException('Frame limits must be positive integers.');
        }
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        $headerEnd = $this->findHeaderEnd();
        while (null === $headerEnd) {
            if ($this->eof) {
                if ('' === $this->buffer) {
                    return null;
                }

                throw new FramingException('The stream ended before the message headers were complete.');
            }

            if (\strlen($this->buffer) > $this->maximumHeaderBytes) {
                throw new FramingException('The message headers exceed the configured limit.');
            }

            $this->readChunk($cancellation);
            $headerEnd = $this->findHeaderEnd();
        }

        if ($headerEnd['offset'] > $this->maximumHeaderBytes) {
            throw new FramingException('The message headers exceed the configured limit.');
        }

        $headerBlock = substr($this->buffer, 0, $headerEnd['offset']);
        $contentLength = $this->parseContentLength($headerBlock);
        $frameLength = $headerEnd['offset'] + $headerEnd['separatorLength'] + $contentLength;

        while (\strlen($this->buffer) < $frameLength) {
            if ($this->eof) {
                throw new FramingException('The stream ended before the message body was complete.');
            }

            $this->readChunk($cancellation);
        }

        $bodyOffset = $headerEnd['offset'] + $headerEnd['separatorLength'];
        $body = substr($this->buffer, $bodyOffset, $contentLength);
        $this->buffer = substr($this->buffer, $frameLength);

        return $body;
    }

    /**
     * @return array{offset: int, separatorLength: int}|null
     */
    private function findHeaderEnd(): ?array
    {
        $offset = strpos($this->buffer, "\r\n\r\n");

        return false === $offset ? null : ['offset' => $offset, 'separatorLength' => 4];
    }

    private function parseContentLength(string $headerBlock): int
    {
        $contentLength = null;

        foreach (explode("\r\n", $headerBlock) as $header) {
            if (!str_contains($header, ':')) {
                throw new FramingException('A message header is malformed.');
            }

            [$name, $value] = array_map('trim', explode(':', $header, 2));
            if ('' === $name) {
                throw new FramingException('A message header name is empty.');
            }

            if (0 !== strcasecmp($name, 'Content-Length')) {
                continue;
            }

            if (null !== $contentLength) {
                throw new FramingException('The message contains duplicate Content-Length headers.');
            }

            if (!preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
                throw new FramingException('The Content-Length header is invalid.');
            }

            $contentLength = filter_var($value, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if (false === $contentLength) {
                throw new FramingException('The Content-Length header is invalid.');
            }
        }

        if (null === $contentLength) {
            throw new FramingException('The message is missing a Content-Length header.');
        }

        if ($contentLength > $this->maximumMessageBytes) {
            throw new FramingException('The message body exceeds the configured limit.');
        }

        return $contentLength;
    }

    private function readChunk(?Cancellation $cancellation): void
    {
        $chunk = $this->stream->read($cancellation);
        if (null === $chunk) {
            $this->eof = true;

            return;
        }

        $this->buffer .= $chunk;
    }
}
