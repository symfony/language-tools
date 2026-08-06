<?php

namespace Symfony\Lsp\Server;

use Symfony\Component\Filesystem\Path;

final class ServerVersion
{
    private readonly string $value;

    public function __construct(?string $version = null)
    {
        if (null === $version) {
            $version = file_get_contents(Path::join(\dirname(__DIR__, 2), 'resources/version'));
            if (false === $version) {
                throw new \RuntimeException('The Symfony LSP version file is unavailable.');
            }
        }

        $version = trim($version);
        if ('' === $version || 1 !== preg_match('/^(?:v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?|dev)$/', $version)) {
            throw new \InvalidArgumentException(\sprintf('Invalid Symfony LSP version "%s".', $version));
        }

        $this->value = ltrim($version, 'v');
    }

    public function value(): string
    {
        return $this->value;
    }
}
