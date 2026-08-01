<?php

namespace Symfony\Lsp\Parser\TreeSitter;

final class NativeTreeSitterParser implements TreeSitterParserInterface
{
    public function __construct(private readonly TreeSitterResultDecoder $decoder)
    {
    }

    public function parse(string $language, string $source): TreeSitterTree
    {
        if (!\function_exists('symfony_lsp_tree_sitter_parse')) {
            throw new \RuntimeException('The Symfony LSP Tree-sitter extension is not loaded.');
        }

        return $this->decoder->decode(symfony_lsp_tree_sitter_parse($language, $source), \strlen($source));
    }
}
