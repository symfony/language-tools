<?php

namespace Symfony\Lsp\Check;

final class CheckOutputWriter
{
    private const CHUNK_BYTES = 8192;
    private const MAX_EMPTY_WRITES = 5000;
    private const RETRY_MICROSECONDS = 1000;

    /** @param resource $stream */
    public function write($stream, string $contents): bool
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
                if (++$emptyWrites > self::MAX_EMPTY_WRITES) {
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
