<?php

namespace Symfony\Lsp\Index;

interface LocatedSourceSymbolInterface extends RangedSourceSymbolInterface
{
    public string $uri { get; }
}
