<?php

namespace Symfony\Lsp\Feature\Messenger;

final class MessengerSourceIndex
{
    /** @var array<string, MessengerSourceFacts> */
    private array $sources = [];
    /** @var array<string, MessengerSourceFacts> */
    private array $overlays = [];

    public function replace(MessengerSourceFacts ...$sources): void
    {
        $this->sources = [];
        foreach ($sources as $source) {
            $this->sources[$source->uri()] = $source;
        }
    }

    public function overlay(MessengerSourceFacts $source): void
    {
        $this->overlays[$source->uri()] = $source;
    }

    public function removeOverlay(string $uri): void
    {
        unset($this->overlays[$uri]);
    }

    /** @return list<MessengerSourceSymbol> */
    public function symbols(MessengerSymbolKind $kind, string $name): array
    {
        $result = [];
        foreach ($this->sources() as $source) {
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
        foreach ($this->sources() as $source) {
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

    /** @return list<MessengerSourceFacts> */
    private function sources(): array
    {
        return [...array_values(array_diff_key($this->sources, $this->overlays)), ...array_values($this->overlays)];
    }
}
