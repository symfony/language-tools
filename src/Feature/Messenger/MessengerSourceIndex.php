<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<MessengerSourceFacts> */
final class MessengerSourceIndex extends AbstractSourceFactsIndex
{
    /** @return list<MessengerSourceSymbol> */
    public function symbols(MessengerSymbolKind $kind, string $name): array
    {
        $result = [];
        foreach ($this->facts() as $source) {
            foreach ($source->symbols() as $symbol) {
                if ($symbol->kind() === $kind && $symbol->name() === $name) {
                    $result[] = $symbol;
                }
            }
        }

        return $result;
    }

    /** @return list<string> */
    public function ancestors(string $className): array
    {
        $parents = [];
        foreach ($this->facts() as $source) {
            foreach ($source->parents() as $class => $classParents) {
                $parents[$class] = $classParents;
            }
        }
        $ancestors = [];
        $pending = $parents[ltrim($className, '\\')] ?? [];
        while ([] !== $pending) {
            $parent = array_shift($pending);
            if (isset($ancestors[$parent])) {
                continue;
            }
            $ancestors[$parent] = true;
            array_push($pending, ...($parents[$parent] ?? []));
        }

        return array_keys($ancestors);
    }
}
