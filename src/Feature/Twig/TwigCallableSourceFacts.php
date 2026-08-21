<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\SourceFactsInterface;

final class TwigCallableSourceFacts implements SourceFactsInterface
{
    /** @param list<TwigCallableDeclaration> $declarations */
    public function __construct(
        private readonly string $uri,
        private readonly array $declarations,
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<TwigCallableDeclaration> */
    public function declarations(): array
    {
        return $this->declarations;
    }

    public function isEmpty(): bool
    {
        return [] === $this->declarations;
    }
}
