<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Document\Range;

interface RangedSourceSymbolInterface
{
    public Range $range { get; }
}
