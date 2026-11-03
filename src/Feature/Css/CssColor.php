<?php

namespace Symfony\Lsp\Feature\Css;

use Symfony\Lsp\Document\Color;

final class CssColor
{
    public function __construct(
        public readonly Color $color,
        public readonly CssColorNotation $notation,
    ) {
    }
}
