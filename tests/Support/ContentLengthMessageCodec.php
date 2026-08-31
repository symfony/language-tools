<?php

namespace Symfony\Lsp\Tests\Support;

final class ContentLengthMessageCodec
{
    private const MAX_HEADER_BYTES = 65536;

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
                throw new \UnexpectedValueException('The Content-Length header exceeds the maximum size.');
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

        return $this->decode($header.$body)[0];
    }

    /** @return array{list<array<string, mixed>>, int} */
    private function decodePrefix(string $transcript): array
    {
        $messages = [];
        $offset = 0;
        $transcriptLength = \strlen($transcript);
        while ($offset < $transcriptLength) {
            $headerEnd = strpos($transcript, "\r\n\r\n", $offset);
            if (false === $headerEnd) {
                if ($transcriptLength - $offset > self::MAX_HEADER_BYTES) {
                    throw new \UnexpectedValueException('The Content-Length header exceeds the maximum size.');
                }

                break;
            }
            if ($headerEnd - $offset > self::MAX_HEADER_BYTES) {
                throw new \UnexpectedValueException('The Content-Length header exceeds the maximum size.');
            }

            $length = $this->contentLength(substr($transcript, $offset, $headerEnd - $offset));
            $bodyOffset = $headerEnd + 4;
            if ($transcriptLength < $bodyOffset + $length) {
                break;
            }

            $messages[] = $this->decodeBody(substr($transcript, $bodyOffset, $length));
            $offset = $bodyOffset + $length;
        }

        return [$messages, $offset];
    }

    private function contentLength(string $headerBlock): int
    {
        $length = null;
        foreach (explode("\r\n", $headerBlock) as $header) {
            if (1 === preg_match('/^Content-Length:\s*(\d+)\s*$/i', $header, $matches)) {
                if (null !== $length) {
                    throw new \UnexpectedValueException('The Content-Length header is duplicated.');
                }
                $length = (int) $matches[1];

                continue;
            }
            if (!str_contains($header, ':')) {
                throw new \UnexpectedValueException('A Content-Length message header is malformed.');
            }
        }
        if (null === $length) {
            throw new \UnexpectedValueException('The Content-Length header is missing.');
        }

        return $length;
    }

    /** @return array<string, mixed> */
    private function decodeBody(string $json): array
    {
        $shape = json_decode($json, flags: \JSON_THROW_ON_ERROR);
        if (!$shape instanceof \stdClass) {
            throw new \UnexpectedValueException('The Content-Length message body must contain a JSON object.');
        }

        $message = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($message)) {
            throw new \UnexpectedValueException('The Content-Length message body must contain a JSON object.');
        }
        foreach ($message as $key => $value) {
            if (!\is_string($key)) {
                throw new \UnexpectedValueException('The Content-Length message body must contain a JSON object with string keys.');
            }
        }

        return $message;
    }
}
