<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;
use Symfony\Lsp\Index\ClassNameKey;
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
        $this->derive();

        return $this->symbols->symbols($kind->name, $name);
    }

    /** @return list<string> */
    public function ancestors(string $className): array
    {
        $this->derive();
        $ancestors = [];
        $pending = $this->parents[ClassNameKey::from($className)] ?? [];
        while ([] !== $pending) {
            $parent = array_shift($pending);
            $key = ClassNameKey::from($parent);
            if (isset($ancestors[$key])) {
                continue;
            }
            $ancestors[$key] = $parent;
            array_push($pending, ...($this->parents[$key] ?? []));
        }

        return array_values($ancestors);
    }

    protected function build(): void
    {
        $this->symbols = new SourceSymbolTable([MessengerSymbolKind::Message->name => ClassNameKey::from(...)]);
        $this->parents = [];
        foreach ($this->facts() as $source) {
            foreach ($source->symbols as $symbol) {
                $this->symbols->add($symbol->kind->name, $symbol);
            }
            foreach ($source->parents as $class => $parents) {
                $this->parents[ClassNameKey::from($class)] = $parents;
            }
        }
    }
}
