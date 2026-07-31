<?php

namespace Symfony\Lsp\Runtime;

use Amp\Cancellation;
use Symfony\Lsp\Project\Project;

final class BridgeInstaller implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly string $bridgeSource,
        private readonly string $serverVersion,
    ) {
    }

    public function initialize(Project $project, RuntimeRefreshMode $mode = RuntimeRefreshMode::Reuse, ?Cancellation $cancellation = null): void
    {
        $cancellation?->throwIfRequested();
        $this->install($project);
    }

    public function install(Project $project): string
    {
        $files = $this->bundleFiles();
        $hash = hash('sha256', serialize($files));
        $baseDirectory = $project->rootPath().'/var/symfony-lsp/'.$this->serverVersion;
        if (!is_dir($baseDirectory) && !mkdir($baseDirectory, 0777, true) && !is_dir($baseDirectory)) {
            throw new \RuntimeException(\sprintf('Unable to create bridge directory "%s".', $baseDirectory));
        }

        $directory = $baseDirectory.'/'.$hash;
        if ($this->isInstalled($directory, $files)) {
            return $directory.'/bridge.php';
        }

        $temporary = $baseDirectory.'/.bridge-'.$hash.'-'.bin2hex(random_bytes(8));
        if (!mkdir($temporary, 0777)) {
            throw new \RuntimeException(\sprintf('Unable to create a bridge directory in "%s".', $baseDirectory));
        }

        try {
            foreach ($files as $relativePath => $contents) {
                $destination = $temporary.'/'.$relativePath;
                $parent = \dirname($destination);
                if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                    throw new \RuntimeException(\sprintf('Unable to create bridge directory "%s".', $parent));
                }
                if (false === file_put_contents($destination, $contents)) {
                    throw new \RuntimeException(\sprintf('Unable to install project bridge file "%s".', $destination));
                }
            }
            if (!@rename($temporary, $directory) && !$this->isInstalled($directory, $files)) {
                throw new \RuntimeException(\sprintf('Unable to install project bridge "%s".', $directory));
            }
        } finally {
            if (is_dir($temporary)) {
                $this->removeDirectory($temporary);
            }
        }

        return $directory.'/bridge.php';
    }

    /** @return array<string, string> */
    private function bundleFiles(): array
    {
        $contents = file_get_contents($this->bridgeSource);
        if (false === $contents) {
            throw new \RuntimeException('Unable to read the bundled project bridge.');
        }
        $files = ['bridge.php' => $contents];
        $sourceDirectory = \dirname($this->bridgeSource).'/bridge';
        if (is_dir($sourceDirectory)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if (false === $contents) {
                    throw new \RuntimeException(\sprintf('Unable to read bundled project bridge file "%s".', $file->getPathname()));
                }
                $relativePath = 'bridge/'.str_replace('\\', '/', substr($file->getPathname(), \strlen($sourceDirectory) + 1));
                $files[$relativePath] = $contents;
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @param array<string, string> $files
     *
     * @phpstan-impure
     */
    private function isInstalled(string $directory, array $files): bool
    {
        foreach ($files as $relativePath => $contents) {
            $destination = $directory.'/'.$relativePath;
            if (!is_file($destination) || hash('sha256', $contents) !== hash_file('sha256', $destination)) {
                return false;
            }
        }

        return true;
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($directory);
    }
}
