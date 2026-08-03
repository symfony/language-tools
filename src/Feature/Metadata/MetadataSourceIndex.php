<?php

namespace Symfony\Lsp\Feature\Metadata;

final class MetadataSourceIndex
{
    /** @var array<string, MetadataSourceFacts> */
    private array $sources = [];
    /** @var array<string, MetadataSourceFacts> */
    private array $overlays = [];

    public function replace(MetadataSourceFacts ...$facts): void
    {
        $this->sources = [];
        foreach ($facts as $item) {
            $this->sources[$item->uri()] = $item;
        }
    }

    public function replaceSource(MetadataSourceFacts $facts): void
    {
        $this->sources[$facts->uri()] = $facts;
    }

    public function removeSource(string $uri): void
    {
        unset($this->sources[$uri]);
    }

    public function overlay(MetadataSourceFacts $facts): void
    {
        $this->overlays[$facts->uri()] = $facts;
    }

    public function removeOverlay(string $uri): void
    {
        unset($this->overlays[$uri]);
    }

    /** @return list<MetadataSourceSymbol> */
    public function symbols(MetadataSymbolKind $kind, ?string $name = null): array
    {
        $symbols = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->symbols() as $symbol) {
                if ($symbol->kind() === $kind && (null === $name || $symbol->name() === $name)) {
                    $symbols[] = $symbol;
                }
            }
        }

        return $symbols;
    }

    /** @return list<string> */
    public function names(MetadataSymbolKind $kind): array
    {
        $names = array_values(array_unique(array_map(static fn (MetadataSourceSymbol $symbol): string => $symbol->name(), $this->symbols($kind))));
        sort($names);

        return $names;
    }

    /** @return list<MetadataSourceFacts> */
    private function facts(): array
    {
        return array_values(array_replace($this->sources, $this->overlays));
    }
}
