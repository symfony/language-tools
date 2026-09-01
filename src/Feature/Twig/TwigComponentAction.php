<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\RangedSourceSymbolInterface;

final class TwigComponentAction implements RangedSourceSymbolInterface
{
    public function __construct(public readonly string $name, public readonly Range $range)
    {
    }
}
