<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Server\ServerVersion;

final class RuntimeApplicationFixture
{
    public readonly string $rootPath;
    public readonly ServerVersion $serverVersion;
    public readonly string $cachePath;

    public function __construct(string $versionPrefix = '0.0.0-test')
    {
        $rootPath = realpath(\dirname(__DIR__).'/Fixtures/RuntimeApplication');
        if (false === $rootPath) {
            throw new \RuntimeException('The runtime application fixture is unavailable.');
        }

        $this->rootPath = $rootPath;
        $this->serverVersion = new ServerVersion($versionPrefix.'.'.bin2hex(random_bytes(8)));
        $this->cachePath = Path::join($rootPath, 'var/symfony-lsp', $this->serverVersion->value());
    }

    public function cleanup(): void
    {
        (new Filesystem())->remove($this->cachePath);
    }
}
