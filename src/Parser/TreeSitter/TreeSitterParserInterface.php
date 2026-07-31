<?php

namespace Symfony\Lsp\Parser\TreeSitter;

interface TreeSitterParserInterface
{
    public function parse(string $language, string $source): TreeSitterTree;
}
