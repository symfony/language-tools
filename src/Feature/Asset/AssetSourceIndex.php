<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Index\SourceFactsOverlayOrder;
use Symfony\Lsp\Index\SourceFactsStore;

final class AssetSourceIndex
{
    /** @var SourceFactsStore<AssetSourceFacts> */
    private readonly SourceFactsStore $facts;

    public function __construct()
    {
        $this->facts = new SourceFactsStore(SourceFactsOverlayOrder::PreserveSavedPosition);
    }

    public function replace(AssetSourceFacts ...$facts): void
    {
        $this->facts->replaceSaved(...$facts);
    }

    public function replaceSource(AssetSourceFacts $facts): void
    {
        $this->facts->replaceSavedFact($facts);
    }

    public function removeSource(string $uri): void
    {
        $this->facts->removeSaved($uri);
    }

    public function overlay(AssetSourceFacts $facts): void
    {
        $this->facts->replaceOverlay($facts);
    }

    public function removeOverlay(string $uri): void
    {
        $this->facts->removeOverlay($uri);
    }

    /** @return list<AssetSourceSymbol> */
    public function symbols(AssetSymbolKind $kind, ?string $name = null): array
    {
        $symbols = [];
        foreach ($this->facts->effective() as $facts) {
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
}
