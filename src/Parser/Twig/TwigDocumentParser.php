<?php

namespace Symfony\Lsp\Parser\Twig;

use Symfony\Lsp\Parser\TreeSitter\TreeSitterParserInterface;

final class TwigDocumentParser
{
    public function __construct(private readonly TreeSitterParserInterface $parser)
    {
    }

    public function parse(string $source): TwigDocument
    {
        return new TwigDocument($source, $this->parser->parse('twig', $source));
    }
}
