<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\RangedSourceSymbolInterface;

final class FormOptionReference implements RangedSourceSymbolInterface
{
    public function __construct(
        public readonly string $className,
        public readonly string $option,
        public readonly Range $range,
    ) {
    }
}
