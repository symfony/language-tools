<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;
use Symfony\Lsp\Index\SourceSymbolTable;

/** @extends AbstractSourceFactsIndex<MessengerSourceFacts> */
final class MessengerSourceIndex extends AbstractSourceFactsIndex
{
    /** @var SourceSymbolTable<MessengerSourceSymbol> */
    private SourceSymbolTable $symbols;

    /** @var array<string, list<string>> */
    private array $parents = [];

    /** @return list<MessengerSourceSymbol> */
    public function symbols(MessengerSymbolKind $kind, string $name): array
    {
        $this->derived();

        return $this->symbols->symbols($kind->name, $name);
    }

    /** @return list<string> */
    public function ancestors(string $className): array
    {
        $this->derived();
        $ancestors = [];
        $pending = $this->parents[ltrim($className, '\\')] ?? [];
        while ([] !== $pending) {
            $parent = array_shift($pending);
            if (isset($ancestors[$parent])) {
                continue;
            }
            $ancestors[$parent] = true;
            array_push($pending, ...($this->parents[$parent] ?? []));
        }

        return array_keys($ancestors);
    }

    protected function build(): void
    {
        $this->symbols = new SourceSymbolTable();
        $this->parents = [];
        foreach ($this->facts() as $source) {
            foreach ($source->symbols as $symbol) {
                $this->symbols->add($symbol->kind->name, $symbol);
            }
            foreach ($source->parents as $class => $parents) {
                $this->parents[$class] = $parents;
            }
        }
    }
}
