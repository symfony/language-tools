<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\RangedSourceSymbolInterface;

final class ConstraintOptionReference implements RangedSourceSymbolInterface
{
    public function __construct(
        public readonly string $constraint,
        public readonly string $option,
        public readonly Range $range,
    ) {
    }
}
