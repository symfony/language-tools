<?php

namespace Symfony\Lsp\Tools;

final class ContentLengthMessageCodec
{
    private const MAX_HEADER_BYTES = 65536;

    public function __construct(
        private readonly bool $strictJsonObject = true,
    ) {
    }

    /** @param array<string, mixed> $message */
    public function encode(array $message): string
    {
        $json = json_encode($message, \JSON_THROW_ON_ERROR);

        return 'Content-Length: '.\strlen($json)."\r\n\r\n".$json;
    }

    /** @return list<array<string, mixed>> */
    public function decode(string $transcript): array
    {
        [$messages, $offset] = $this->decodePrefix($transcript);
        if ($offset !== \strlen($transcript)) {
            throw new \UnexpectedValueException('The Content-Length transcript ends with an incomplete message.');
        }

        return $messages;
    }

    /** @return list<array<string, mixed>> */
    public function decodeAvailable(string $transcript): array
    {
        return $this->decodePrefix($transcript)[0];
    }

    /** @return array<string, mixed>|null */
    public function decodeNext(string &$transcript): ?array
    {
        [$json, $offset] = $this->frame($transcript, 0);
        if (null === $json) {
            return null;
        }

        $transcript = substr($transcript, $offset);

        return $this->decodeBody($json);
    }

    /**
     * @param resource $stream
     *
     * @return array<string, mixed>
     */
    public function read($stream): array
    {
        $header = '';
        while (!str_ends_with($header, "\r\n\r\n")) {
            $line = fgets($stream);
            if (false === $line) {
                throw new \UnexpectedValueException('The Content-Length stream ended before a complete header was received.');
            }
            $header .= $line;
            if (\strlen($header) > self::MAX_HEADER_BYTES + 4) {
                throw new ContentLengthMessageException(ContentLengthMessageException::HEADER_TOO_LARGE, 'The Content-Length header exceeds the maximum size.');
            }
        }

        $length = $this->contentLength(substr($header, 0, -4));
        $body = '';
        while (($remaining = $length - \strlen($body)) > 0) {
            $chunk = fread($stream, $remaining);
            if (false === $chunk || '' === $chunk) {
                throw new \UnexpectedValueException('The Content-Length stream ended before a complete body was received.');
            }
            $body .= $chunk;
        }

        return $this->decodeBody($body);
    }

    /** @return array{list<array<string, mixed>>, int} */
    private function decodePrefix(string $transcript): array
    {
        $messages = [];
        $offset = 0;
        while ($offset < \strlen($transcript)) {
            [$json, $nextOffset] = $this->frame($transcript, $offset);
            if (null === $json) {
                break;
            }

            $messages[] = $this->decodeBody($json);
            $offset = $nextOffset;
        }

        return [$messages, $offset];
    }

    /** @return array{string|null, int} */
    private function frame(string $transcript, int $offset): array
    {
        $transcriptLength = \strlen($transcript);
        $headerEnd = strpos($transcript, "\r\n\r\n", $offset);
        if (false === $headerEnd) {
            if ($transcriptLength - $offset > self::MAX_HEADER_BYTES) {
                throw new ContentLengthMessageException(ContentLengthMessageException::HEADER_TOO_LARGE, 'The Content-Length header exceeds the maximum size.');
            }

            return [null, $offset];
        }
        if ($headerEnd - $offset > self::MAX_HEADER_BYTES) {
            throw new ContentLengthMessageException(ContentLengthMessageException::HEADER_TOO_LARGE, 'The Content-Length header exceeds the maximum size.');
        }

        $length = $this->contentLength(substr($transcript, $offset, $headerEnd - $offset));
        $bodyOffset = $headerEnd + 4;
        if ($transcriptLength < $bodyOffset + $length) {
            return [null, $offset];
        }

        return [substr($transcript, $bodyOffset, $length), $bodyOffset + $length];
    }

    private function contentLength(string $headerBlock): int
    {
        $length = null;
        foreach (explode("\r\n", $headerBlock) as $header) {
            if (1 === preg_match('/^Content-Length:\s*(\d+)\s*$/i', $header, $matches)) {
                if (null !== $length) {
                    throw new ContentLengthMessageException(ContentLengthMessageException::DUPLICATE_HEADER, 'The Content-Length header is duplicated.');
                }
                $length = (int) $matches[1];

                continue;
            }
            if (!str_contains($header, ':')) {
                throw new ContentLengthMessageException(ContentLengthMessageException::MALFORMED_HEADER, 'A Content-Length message header is malformed.');
            }
        }
        if (null === $length) {
            throw new ContentLengthMessageException(ContentLengthMessageException::MISSING_HEADER, 'The Content-Length header is missing.');
        }

        return $length;
    }

    /** @return array<string, mixed> */
    private function decodeBody(string $json): array
    {
        $message = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($message)) {
            throw new ContentLengthMessageException(ContentLengthMessageException::BODY_NOT_OBJECT, 'The Content-Length message body must contain a JSON object.');
        }
        if ($this->strictJsonObject && !json_decode($json, flags: \JSON_THROW_ON_ERROR) instanceof \stdClass) {
            throw new ContentLengthMessageException(ContentLengthMessageException::BODY_NOT_OBJECT, 'The Content-Length message body must contain a JSON object.');
        }
        foreach ($message as $key => $value) {
            if (!\is_string($key)) {
                throw new ContentLengthMessageException(ContentLengthMessageException::BODY_KEYS_NOT_STRINGS, 'The Content-Length message body must contain a JSON object with string keys.');
            }
        }

        return $message;
    }
}
