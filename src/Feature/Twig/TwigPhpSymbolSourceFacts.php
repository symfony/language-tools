<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\SourceFactsInterface;

final class TwigPhpSymbolSourceFacts implements SourceFactsInterface
{
    /**
     * @param list<TwigPhpSymbolDeclaration> $declarations
     * @param list<TwigPhpSymbolReference>   $references
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $declarations = [],
        public readonly array $references = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->declarations && [] === $this->references;
    }
}
