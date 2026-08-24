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
        while ('' !== $contents) {
            $written = @fwrite($stream, substr($contents, 0, self::CHUNK_BYTES));
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
            $contents = substr($contents, $written);
        }

        return true;
    }
}
