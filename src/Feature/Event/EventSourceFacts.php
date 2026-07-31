<?php

namespace Symfony\Lsp\Feature\Event;

final class EventSourceFacts
{
    /**
     * @param list<EventSourceSymbol>          $symbols
     * @param list<InvalidEventListenerMethod> $invalidListenerMethods
     */
    public function __construct(
        private readonly string $uri,
        private readonly array $symbols,
        private readonly array $invalidListenerMethods = [],
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<EventSourceSymbol> */
    public function symbols(): array
    {
        return $this->symbols;
    }

    /** @return list<InvalidEventListenerMethod> */
    public function invalidListenerMethods(): array
    {
        return $this->invalidListenerMethods;
    }
}
