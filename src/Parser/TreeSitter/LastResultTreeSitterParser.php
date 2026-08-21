<?php

namespace Symfony\Lsp\Parser\TreeSitter;

final class LastResultTreeSitterParser implements TreeSitterParserInterface
{
    private ?string $language = null;
    private ?string $source = null;
    private ?TreeSitterTree $tree = null;

    public function __construct(private readonly TreeSitterParserInterface $parser)
    {
    }

    public function parse(string $language, string $source): TreeSitterTree
    {
        if ($language === $this->language && $source === $this->source && null !== $this->tree) {
            return $this->tree;
        }
        $tree = $this->parser->parse($language, $source);
        $this->language = $language;
        $this->source = $source;
        $this->tree = $tree;

        return $tree;
    }
}
