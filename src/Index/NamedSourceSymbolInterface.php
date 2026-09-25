<?php

namespace Symfony\Lsp\Index;

interface NamedSourceSymbolInterface
{
    public string $name { get; }

    public bool $declaration { get; }
}
