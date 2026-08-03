<?php

namespace Symfony\Lsp\Feature\Asset;

final class Asset
{
    public function __construct(
        private readonly string $logicalPath,
        private readonly string $sourcePath,
        private readonly bool $vendor,
    ) {
    }

    public function logicalPath(): string
    {
        return $this->logicalPath;
    }

    public function sourcePath(): string
    {
        return $this->sourcePath;
    }

    public function isVendor(): bool
    {
        return $this->vendor;
    }
}
