<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<MessengerSourceFacts> */
final class MessengerSourceIndex extends AbstractSourceFactsIndex
{
    private bool $indexed = false;

    /** @var array<string, array<string, list<MessengerSourceSymbol>>> */
    private array $symbols = [];

    /** @var array<string, list<string>> */
    private array $parents = [];

    /** @return list<MessengerSourceSymbol> */
    public function symbols(MessengerSymbolKind $kind, string $name): array
    {
        $this->index();

        return $this->symbols[$kind->name][$name] ?? [];
    }

    /** @return list<string> */
    public function ancestors(string $className): array
    {
        $this->index();
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
        $this->parents = [];
        foreach ($this->facts() as $source) {
            foreach ($source->symbols() as $symbol) {
                $this->symbols[$symbol->kind()->name][$symbol->name()][] = $symbol;
            }
            foreach ($source->parents() as $class => $parents) {
                $this->parents[$class] = $parents;
            }
        }
        $this->indexed = true;
    }
}
