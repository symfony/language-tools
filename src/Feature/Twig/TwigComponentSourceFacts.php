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
        private readonly string $uri,
        private readonly array $components,
        private readonly array $references,
        private readonly array $actionReferences = [],
        private readonly array $events = [],
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

    /** @return list<TwigComponentActionReference> */
    public function actionReferences(): array
    {
        return $this->actionReferences;
    }

    /** @return list<LiveComponentEvent> */
    public function events(): array
    {
        return $this->events;
    }

    public function isEmpty(): bool
    {
        return [] === $this->components && [] === $this->references && [] === $this->actionReferences && [] === $this->events;
    }
}
