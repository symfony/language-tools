<?php

namespace Symfony\Lsp\Server;

final class StreamWriter
{
    private const CHUNK_BYTES = 8192;
    private const MAX_EMPTY_WRITES = 5000;
    private const RETRY_MICROSECONDS = 1000;

    /**
     * Writes in chunks so that large payloads are never copied as a whole.
     *
     * @param resource $stream
     * @param bool     $retryEmptyWrites waits and retries instead of failing when the stream accepts no byte, as a non-blocking standard stream temporarily does
     */
    public static function write($stream, string $contents, bool $retryEmptyWrites = false): bool
    {
        $emptyWrites = 0;
        $length = \strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($stream, substr($contents, $offset, self::CHUNK_BYTES));
            if (false === $written) {
                return false;
            }
            if (0 === $written) {
                if (!$retryEmptyWrites || ++$emptyWrites > self::MAX_EMPTY_WRITES) {
                    return false;
                }
                usleep(self::RETRY_MICROSECONDS);

                continue;
            }

            $emptyWrites = 0;
            $offset += $written;
        }

        return true;
    }
}
