<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigCallableArgumentReference
{
    public function __construct(
        public readonly string $name,
        public readonly Range $range,
    ) {
    }
}
