<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<EventSourceFacts> */
final class EventSourceIndex extends AbstractSourceFactsIndex
{
    /** @return list<EventSourceSymbol> */
    public function symbols(string $name): array
    {
        $symbols = [];
        foreach ($this->facts() as $source) {
            foreach ($source->symbols() as $symbol) {
                if ($symbol->name() === ltrim($name, '\\')) {
                    $symbols[] = $symbol;
                }
            }
        }

        return $symbols;
    }
}
