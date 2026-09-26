<?php

namespace Symfony\Lsp\Parser\Twig;

use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;

final class TwigCallArgument
{
    /**
     * @param ?TreeSitterNode $node The node spanning exactly the value, null when the tree does not reflect it
     */
    public function __construct(
        public readonly ?string $name,
        public readonly int $start,
        public readonly int $end,
        public readonly ?TreeSitterNode $node,
        private readonly TwigDocument $document,
    ) {
    }

    public function literal(): ?TwigStringLiteral
    {
        return $this->document->stringLiteralAt($this->start, $this->end);
    }
}
