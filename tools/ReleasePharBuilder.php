<?php

namespace Symfony\Lsp\Tools;

require_once __DIR__.'/InteractiveProcessRunner.php';
require_once __DIR__.'/ReleasePharDownloader.php';
require_once __DIR__.'/ReleaseReference.php';

final class ReleasePharBuilder
{
    public function __construct(
        private readonly string $root,
        private readonly ReleasePharDownloader $downloader,
        private readonly InteractiveProcessRunner $processes,
    ) {
    }

    public function build(ReleaseReference $reference): string
    {
        $buildDirectory = $this->root.'/var/build/release';
        $outputDirectory = $this->root.'/build';
        foreach ([$buildDirectory, $outputDirectory] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create the release build directories.');
            }
        }

        $versionPath = $this->root.'/resources/version';
        $sourceVersion = file_get_contents($versionPath);
        if (false === $sourceVersion) {
            throw new \RuntimeException('Unable to read the source version.');
        }

        try {
            if (false === file_put_contents($versionPath, $reference->embeddedVersion."\n")) {
                throw new \RuntimeException('Unable to write the release version.');
            }

            $boxPath = $buildDirectory.'/box.phar';
            $this->downloader->download($boxPath);
            if (0 !== $this->processes->run([\PHP_BINARY, $boxPath, 'compile', '--no-parallel'], $this->root)) {
                throw new \RuntimeException('Unable to compile the server PHAR.');
            }

            $pharPath = $outputDirectory.'/symfony-lsp.phar';
            if (!is_file($pharPath)) {
                throw new \RuntimeException('The compiled server PHAR is missing.');
            }
            if (0 !== $this->processes->run([
                \PHP_BINARY,
                $this->root.'/tools/smoke-test-server',
                '--commands-only',
                '--php',
                $pharPath,
                $reference->embeddedVersion,
            ], $this->root)) {
                throw new \RuntimeException('The server PHAR smoke test failed.');
            }

            return $pharPath;
        } finally {
            if (false === file_put_contents($versionPath, $sourceVersion)) {
                throw new \RuntimeException('Unable to restore the source version.');
            }
        }
    }
}
