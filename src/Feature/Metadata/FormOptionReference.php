<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\Range;

final class FormOptionReference
{
    public function __construct(
        public readonly string $className,
        public readonly string $option,
        public readonly Range $range,
    ) {
    }
}
