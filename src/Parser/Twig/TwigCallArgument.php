<?php

namespace Symfony\Lsp\Parser\Twig;

use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;

final class TwigCallArgument
{
    public function __construct(
        public readonly TreeSitterNode $node,
        public readonly ?string $name,
        private readonly TwigDocument $document,
    ) {
    }

    public function literal(): ?TwigStringLiteral
    {
        return $this->document->stringLiteral($this->node) ?? $this->document->soleStringLiteral($this->node);
    }
}
