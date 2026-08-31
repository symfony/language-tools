<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class TestWorkspace
{
    public readonly string $rootPath;

    public function __construct(string $prefix = 'symfony-lsp-test-')
    {
        $this->rootPath = Path::join(sys_get_temp_dir(), $prefix.bin2hex(random_bytes(8)));
        (new Filesystem())->mkdir($this->rootPath);
    }

    public function path(string $relativePath = ''): string
    {
        return '' === $relativePath ? $this->rootPath : Path::join($this->rootPath, $relativePath);
    }

    public function mkdir(string ...$relativePaths): void
    {
        (new Filesystem())->mkdir(array_map($this->path(...), $relativePaths));
    }

    public function write(string $relativePath, string $contents): string
    {
        $path = $this->path($relativePath);
        (new Filesystem())->mkdir(\dirname($path));
        file_put_contents($path, $contents);

        return $path;
    }

    public function executable(string $relativePath, string $contents): string
    {
        $path = $this->write($relativePath, $contents);
        chmod($path, 0755);

        return $path;
    }

    public function cleanup(): void
    {
        (new Filesystem())->remove($this->rootPath);
    }
}
