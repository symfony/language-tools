<?php

namespace Symfony\Lsp\Feature\Css;

use Symfony\Lsp\Document\Color;
use Symfony\Lsp\Document\Range;

final class CssTokenColor
{
    /**
     * @param ?CssColorNotation $notation the notation of a raw color, null for a reference to another token
     * @param bool              $plain    whether the value is an unquoted YAML scalar, where "#" starts a comment
     */
    public function __construct(
        public readonly Range $range,
        public readonly string $value,
        public readonly Color $color,
        public readonly ?CssColorNotation $notation,
        public readonly bool $plain = false,
    ) {
    }
}
