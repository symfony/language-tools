<?php

namespace Symfony\Lsp\Parser\Php;

interface PhpParserInterface
{
    public function parse(string $source): PhpDocument;
}
