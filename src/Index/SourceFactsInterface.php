<?php

namespace Symfony\Lsp\Index;

interface SourceFactsInterface
{
    public string $uri { get; }

    public function isEmpty(): bool;
}
