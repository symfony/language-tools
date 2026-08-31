<?php

namespace Symfony\Lsp\Parser;

interface CommentParserInterface
{
    public function parse(string $source): CommentParseResult;

    public function mask(string $source): string;

    /** @return list<SourceComment> */
    public function comments(string $source): array;
}
