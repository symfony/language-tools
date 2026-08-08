<?php

namespace Symfony\Lsp\Feature\Messenger;

final class MessengerSourceFacts
{
    /**
     * @param list<MessengerSourceSymbol> $symbols
     * @param array<string, list<string>> $parents
     * @param list<string>                $handlers
     */
    public function __construct(
        private readonly string $uri,
        private readonly array $symbols,
        private readonly array $parents = [],
        private readonly array $handlers = [],
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<MessengerSourceSymbol> */
    public function symbols(): array
    {
        return $this->symbols;
    }

    /** @return array<string, list<string>> */
    public function parents(): array
    {
        return $this->parents;
    }

    /** @return list<string> */
    public function handlers(): array
    {
        return $this->handlers;
    }
}
