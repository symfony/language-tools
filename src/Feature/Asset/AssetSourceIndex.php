<?php

namespace Symfony\Lsp\Feature\Asset;

final class AssetSourceIndex
{
    /** @var array<string, AssetSourceFacts> */
    private array $sources = [];
    /** @var array<string, AssetSourceFacts> */
    private array $overlays = [];

    public function replace(AssetSourceFacts ...$facts): void
    {
        $this->sources = [];
        foreach ($facts as $item) {
            $this->sources[$item->uri()] = $item;
        }
    }

    public function replaceSource(AssetSourceFacts $facts): void
    {
        $this->sources[$facts->uri()] = $facts;
    }

    public function removeSource(string $uri): void
    {
        unset($this->sources[$uri]);
    }

    public function overlay(AssetSourceFacts $facts): void
    {
        $this->overlays[$facts->uri()] = $facts;
    }

    public function removeOverlay(string $uri): void
    {
        unset($this->overlays[$uri]);
    }

    /** @return list<AssetSourceSymbol> */
    public function symbols(AssetSymbolKind $kind, ?string $name = null): array
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
    public function declarationNames(AssetSymbolKind $kind): array
    {
        $names = [];
        foreach ($this->symbols($kind) as $symbol) {
            if ($symbol->isDeclaration()) {
                $names[$symbol->name()] = true;
            }
        }
        ksort($names);

        return array_keys($names);
    }

    /** @return list<AssetSourceFacts> */
    private function facts(): array
    {
        return array_values(array_replace($this->sources, $this->overlays));
    }
}
