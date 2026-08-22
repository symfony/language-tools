<?php

namespace Symfony\Lsp\Parser\Php;

final class LastResultPhpCommentParser implements PhpCommentParserInterface
{
    private ?string $source = null;
    private ?string $masked = null;

    public function __construct(private readonly PhpCommentParserInterface $parser)
    {
    }

    public function mask(string $source): string
    {
        if ($source === $this->source && null !== $this->masked) {
            return $this->masked;
        }
        $masked = $this->parser->mask($source);
        $this->source = $source;
        $this->masked = $masked;

        return $masked;
    }
}
