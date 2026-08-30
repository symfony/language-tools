<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<EventSourceFacts> */
final class EventSourceIndex extends AbstractSourceFactsIndex
{
    private bool $indexed = false;

    /** @var array<string, list<EventSourceSymbol>> */
    private array $symbols = [];

    /** @return list<EventSourceSymbol> */
    public function symbols(string $name): array
    {
        $this->index();

        return $this->symbols[ltrim($name, '\\')] ?? [];
    }

    protected function factsChanged(): void
    {
        $this->indexed = false;
    }

    private function index(): void
    {
        if ($this->indexed) {
            return;
        }

        $this->symbols = [];
        foreach ($this->facts() as $source) {
            foreach ($source->symbols as $symbol) {
                $this->symbols[$symbol->name][] = $symbol;
            }
        }
        $this->indexed = true;
    }
}
