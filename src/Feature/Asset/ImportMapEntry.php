<?php

namespace Symfony\Lsp\Feature\Asset;

final class ImportMapEntry
{
    public function __construct(
        private readonly string $name,
        private readonly string $path,
        private readonly bool $entrypoint,
        private readonly ?string $version,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isEntrypoint(): bool
    {
        return $this->entrypoint;
    }

    public function version(): ?string
    {
        return $this->version;
    }
}
