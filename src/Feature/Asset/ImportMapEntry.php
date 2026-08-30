<?php

namespace Symfony\Lsp\Feature\Asset;

final class ImportMapEntry
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly bool $entrypoint,
        public readonly ?string $version,
    ) {
    }
}
