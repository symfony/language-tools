<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigComponentActionReference
{
    public function __construct(
        public readonly string $component,
        public readonly string $action,
        public readonly string $uri,
        public readonly Range $range,
    ) {
    }
}
