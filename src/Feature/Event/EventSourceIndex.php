<?php

namespace Symfony\Lsp\Feature\Event;

final class EventSourceIndex
{
    /** @var array<string, EventSourceFacts> */
    private array $sources = [];
    /** @var array<string, EventSourceFacts> */
    private array $overlays = [];

    public function replace(EventSourceFacts ...$sources): void
    {
        $this->sources = [];
        foreach ($sources as $source) {
            $this->sources[$source->uri()] = $source;
        }
    }

    public function replaceSource(EventSourceFacts $source): void
    {
        $this->sources[$source->uri()] = $source;
    }

    public function removeSource(string $uri): void
    {
        unset($this->sources[$uri]);
    }

    public function overlay(EventSourceFacts $source): void
    {
        $this->overlays[$source->uri()] = $source;
    }

    public function removeOverlay(string $uri): void
    {
        unset($this->overlays[$uri]);
    }

    /** @return list<EventSourceSymbol> */
    public function symbols(string $name): array
    {
        $symbols = [];
        foreach ($this->sources() as $source) {
            foreach ($source->symbols() as $symbol) {
                if ($symbol->name() === ltrim($name, '\\')) {
                    $symbols[] = $symbol;
                }
            }
        }

        return $symbols;
    }

    /** @return list<EventSourceFacts> */
    private function sources(): array
    {
        return [...array_values(array_diff_key($this->sources, $this->overlays)), ...array_values($this->overlays)];
    }
}
