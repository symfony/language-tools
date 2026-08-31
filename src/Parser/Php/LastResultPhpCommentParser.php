<?php

namespace Symfony\Lsp\Parser\Php;

use Symfony\Lsp\Parser\CommentParseResult;

final class LastResultPhpCommentParser implements PhpCommentParserInterface
{
    private ?string $source = null;
    private ?CommentParseResult $result = null;

    public function __construct(private readonly PhpCommentParserInterface $parser)
    {
    }

    public function parse(string $source): CommentParseResult
    {
        if ($source !== $this->source || null === $this->result) {
            $this->source = $source;
            $this->result = $this->parser->parse($source);
        }

        return $this->result;
    }

    public function mask(string $source): string
    {
        return $this->parse($source)->masked;
    }

    public function comments(string $source): array
    {
        return $this->parse($source)->comments;
    }
}
