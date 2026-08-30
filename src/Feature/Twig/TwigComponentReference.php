<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigComponentReference
{
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
    ) {
    }
}
