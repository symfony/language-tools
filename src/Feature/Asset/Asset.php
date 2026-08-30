<?php

namespace Symfony\Lsp\Feature\Asset;

final class Asset
{
    public function __construct(
        public readonly string $logicalPath,
        public readonly string $sourcePath,
        public readonly bool $vendor,
    ) {
    }
}
