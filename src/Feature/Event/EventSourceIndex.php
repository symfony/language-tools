<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<EventSourceFacts> */
final class EventSourceIndex extends AbstractSourceFactsIndex
{
    /** @var array<string, list<EventSourceSymbol>> */
    private array $symbols = [];

    /** @return list<EventSourceSymbol> */
    public function symbols(string $name): array
    {
        $this->derived();

        return $this->symbols[ltrim($name, '\\')] ?? [];
    }

    protected function build(): void
    {
        $this->symbols = [];
        foreach ($this->facts() as $source) {
            foreach ($source->symbols as $symbol) {
                $this->symbols[$symbol->name][] = $symbol;
            }
        }
    }
}
