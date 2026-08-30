<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\Range;

final class MetadataCompletionContext
{
    /** @param list<array{label: string, class: string}> $candidates */
    public function __construct(
        public readonly MetadataCompletionKind $kind,
        public readonly string $prefix,
        public readonly Range $range,
        public readonly ?string $owner = null,
        public readonly array $candidates = [],
    ) {
    }
}
