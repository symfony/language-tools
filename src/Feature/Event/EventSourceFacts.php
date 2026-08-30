<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Index\SourceFactsInterface;

final class EventSourceFacts implements SourceFactsInterface
{
    /**
     * @param list<EventSourceSymbol>          $symbols
     * @param list<InvalidEventListenerMethod> $invalidListenerMethods
     * @param list<string>                     $listeners
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $symbols,
        public readonly array $invalidListenerMethods = [],
        public readonly array $listeners = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->symbols && [] === $this->invalidListenerMethods && [] === $this->listeners;
    }
}
