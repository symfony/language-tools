<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\Range;

final class ConstraintOptionReference
{
    public function __construct(
        public readonly string $constraint,
        public readonly string $option,
        public readonly Range $range,
    ) {
    }
}
