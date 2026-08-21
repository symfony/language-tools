<?php

namespace Symfony\Lsp\Index;

interface SourceFactsInterface
{
    public function uri(): string;

    public function isEmpty(): bool;
}
