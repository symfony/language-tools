<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;
use Symfony\Lsp\Index\SourceSymbolTable;

/** @extends AbstractSourceFactsIndex<AssetSourceFacts> */
final class AssetSourceIndex extends AbstractSourceFactsIndex
{
    /** @var SourceSymbolTable<AssetSourceSymbol> */
    private SourceSymbolTable $symbols;

    /** @return list<AssetSourceSymbol> */
    public function symbols(AssetSymbolKind $kind, ?string $name = null): array
    {
        $this->derived();

        return $this->symbols->symbols($kind->value, $name);
    }

    /** @return list<string> */
    public function declarationNames(AssetSymbolKind $kind): array
    {
        $this->derived();

        return $this->symbols->declarationNames($kind->value);
    }

    protected function build(): void
    {
        $this->symbols = new SourceSymbolTable();
        foreach ($this->facts() as $facts) {
            foreach ($facts->symbols as $symbol) {
                $this->symbols->add($symbol->kind->value, $symbol);
            }
        }
    }
}
