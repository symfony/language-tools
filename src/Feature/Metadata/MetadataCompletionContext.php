<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\Range;

final class MetadataCompletionContext
{
    public function __construct(
        private readonly MetadataCompletionKind $kind,
        private readonly string $prefix,
        private readonly Range $range,
        private readonly ?string $owner = null,
    ) {
    }

    public function kind(): MetadataCompletionKind
    {
        return $this->kind;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function range(): Range
    {
        return $this->range;
    }

    public function owner(): ?string
    {
        return $this->owner;
    }
}
