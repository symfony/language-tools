<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class BridgeFixtureWorkspace
{
    public readonly string $path;

    public function __construct(string $prefix = 'symfony-lsp-bridge-')
    {
        $this->path = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(8));
        $this->makeDirectory('vendor');
    }

    public function cleanup(): void
    {
        if (file_exists($this->path)) {
            $this->removeDirectory($this->path);
        }
    }

    public function makeDirectory(string $path): string
    {
        $absolutePath = $this->path($path);
        if (!is_dir($absolutePath)) {
            mkdir($absolutePath, 0777, true);
        }

        return $absolutePath;
    }

    public function write(string $path, string $contents): string
    {
        $absolutePath = $this->path($path);
        $this->makeDirectory(\dirname($path));
        file_put_contents($absolutePath, $contents);

        return $absolutePath;
    }

    public function writeExecutable(string $path, string $contents): string
    {
        $absolutePath = $this->write($path, $contents);
        chmod($absolutePath, 0755);

        return $absolutePath;
    }

    public function read(string $path): string
    {
        return (string) file_get_contents($this->path($path));
    }

    public function replace(string $path, string $search, string $replacement): int
    {
        $contents = str_replace($search, $replacement, $this->read($path), $count);
        $this->write($path, $contents);

        return $count;
    }

    public function remove(string $path): void
    {
        $absolutePath = $this->path($path);
        if (is_dir($absolutePath) && !is_link($absolutePath)) {
            $this->removeDirectory($absolutePath);
        } elseif (file_exists($absolutePath) || is_link($absolutePath)) {
            unlink($absolutePath);
        }
    }

    public function path(string $path = ''): string
    {
        return '' === $path || '.' === $path ? $this->path : $this->path.'/'.ltrim($path, '/');
    }

    private function removeDirectory(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }
        rmdir($path);
    }
}
