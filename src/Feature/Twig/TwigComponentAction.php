<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigComponentAction
{
    public function __construct(public readonly string $name, public readonly Range $range)
    {
    }
}
