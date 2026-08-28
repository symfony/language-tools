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
        private readonly string $uri,
        private readonly array $declarations = [],
        private readonly array $references = [],
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<TwigPhpSymbolDeclaration> */
    public function declarations(): array
    {
        return $this->declarations;
    }

    /** @return list<TwigPhpSymbolReference> */
    public function references(): array
    {
        return $this->references;
    }

    public function isEmpty(): bool
    {
        return [] === $this->declarations && [] === $this->references;
    }
}
