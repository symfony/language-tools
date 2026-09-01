<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\RangedSourceSymbolInterface;

final class TwigComponentActionReference implements RangedSourceSymbolInterface
{
    public function __construct(
        public readonly string $component,
        public readonly string $action,
        public readonly string $uri,
        public readonly Range $range,
    ) {
    }
}
