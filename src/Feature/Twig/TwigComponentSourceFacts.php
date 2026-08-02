<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigComponentSourceFacts
{
    /**
     * @param list<TwigComponent>          $components
     * @param list<TwigComponentReference> $references
     */
    public function __construct(
        private readonly string $uri,
        private readonly array $components,
        private readonly array $references,
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<TwigComponent> */
    public function components(): array
    {
        return $this->components;
    }

    /** @return list<TwigComponentReference> */
    public function references(): array
    {
        return $this->references;
    }
}
