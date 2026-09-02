<?php

namespace Symfony\Lsp\Tools;

final class ReleasePharDownloader
{
    public function __construct(
        private readonly string $url,
        private readonly string $checksum,
        private readonly int $attempts = 5,
        private readonly int $retryDelaySeconds = 2,
    ) {
        if (1 > $attempts) {
            throw new \InvalidArgumentException('The download attempt count must be positive.');
        }
        if (0 > $retryDelaySeconds) {
            throw new \InvalidArgumentException('The download retry delay cannot be negative.');
        }
    }

    public function download(string $destination): void
    {
        $directory = \dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the release build directory.');
        }

        $temporary = $destination.'.download-'.bin2hex(random_bytes(6));
        try {
            $lastError = 'unknown error';
            for ($attempt = 1; $attempt <= $this->attempts; ++$attempt) {
                $contents = @file_get_contents($this->url);
                if (false !== $contents) {
                    if (false === file_put_contents($temporary, $contents)) {
                        throw new \RuntimeException('Unable to write the release PHAR download.');
                    }

                    if (hash_equals($this->checksum, (string) hash_file('sha256', $temporary))) {
                        if (is_file($destination) && !unlink($destination)) {
                            throw new \RuntimeException('Unable to replace the existing release PHAR.');
                        }
                        if (!rename($temporary, $destination)) {
                            throw new \RuntimeException('Unable to install the release PHAR.');
                        }

                        return;
                    }

                    $lastError = 'checksum mismatch';
                    @unlink($temporary);
                } else {
                    $lastError = error_get_last()['message'] ?? 'download failed';
                }

                if ($attempt < $this->attempts && 0 < $this->retryDelaySeconds) {
                    sleep($this->retryDelaySeconds);
                }
            }

            throw new \RuntimeException('Unable to download the release PHAR: '.$lastError.'.');
        } finally {
            @unlink($temporary);
        }
    }
}
