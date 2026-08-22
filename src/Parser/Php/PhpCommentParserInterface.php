<?php

namespace Symfony\Lsp\Parser\Php;

interface PhpCommentParserInterface
{
    public function mask(string $source): string;
}
