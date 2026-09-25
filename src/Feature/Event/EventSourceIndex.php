<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;
use Symfony\Lsp\Index\SourceSymbolTable;

/** @extends AbstractSourceFactsIndex<EventSourceFacts> */
final class EventSourceIndex extends AbstractSourceFactsIndex
{
    private const KIND = 'event';

    /** @var SourceSymbolTable<EventSourceSymbol> */
    private SourceSymbolTable $symbols;

    /** @return list<EventSourceSymbol> */
    public function symbols(string $name): array
    {
        $this->derived();

        return $this->symbols->symbols(self::KIND, ltrim($name, '\\'));
    }

    protected function build(): void
    {
        $this->symbols = new SourceSymbolTable();
        foreach ($this->facts() as $facts) {
            foreach ($facts->symbols as $symbol) {
                $this->symbols->add(self::KIND, $symbol);
            }
        }
    }
}
