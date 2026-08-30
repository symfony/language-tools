<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\SourceFactsInterface;

final class TwigComponentSourceFacts implements SourceFactsInterface
{
    /**
     * @param list<TwigComponent>                $components
     * @param list<TwigComponentReference>       $references
     * @param list<TwigComponentActionReference> $actionReferences
     * @param list<LiveComponentEvent>           $events
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $components,
        public readonly array $references,
        public readonly array $actionReferences = [],
        public readonly array $events = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->components && [] === $this->references && [] === $this->actionReferences && [] === $this->events;
    }
}
