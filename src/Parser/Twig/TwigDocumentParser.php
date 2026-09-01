<?php

namespace Symfony\Lsp\Parser\Twig;

use Symfony\Lsp\Parser\TreeSitter\TreeSitterParserInterface;

final class TwigDocumentParser
{
    public function __construct(
        private readonly TreeSitterParserInterface $parser,
        private readonly TwigCommentParser $commentParser,
    ) {
    }

    public function parse(string $source): TwigDocument
    {
        $masked = $this->commentParser->mask($source);

        return new TwigDocument($source, $masked, $this->parser->parse('twig', $masked));
    }
}
