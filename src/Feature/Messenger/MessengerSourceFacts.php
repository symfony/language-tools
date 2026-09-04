<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Index\SourceFactsInterface;

final class MessengerSourceFacts implements SourceFactsInterface
{
    /**
     * @param list<MessengerSourceSymbol>     $symbols
     * @param array<string, list<string>>     $parents
     * @param list<string>                    $handlers
     * @param list<MessengerHandlerSignature> $handlerSignatures
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $symbols,
        public readonly array $parents = [],
        public readonly array $handlers = [],
        public readonly array $handlerSignatures = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->symbols && [] === $this->parents && [] === $this->handlers && [] === $this->handlerSignatures;
    }
}
