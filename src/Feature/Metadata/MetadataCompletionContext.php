<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\Range;

final class MetadataCompletionContext
{
    /** @param list<array{label: string, class: string}> $candidates */
    public function __construct(
        private readonly MetadataCompletionKind $kind,
        private readonly string $prefix,
        private readonly Range $range,
        private readonly ?string $owner = null,
        private readonly array $candidates = [],
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

    /** @return list<array{label: string, class: string}> */
    public function candidates(): array
    {
        return $this->candidates;
    }
}
