<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;
use Symfony\Lsp\Index\SourceFactsOverlayOrder;

/** @extends AbstractSourceFactsIndex<AssetSourceFacts> */
final class AssetSourceIndex extends AbstractSourceFactsIndex
{
    public function __construct()
    {
        parent::__construct(SourceFactsOverlayOrder::PreserveSavedPosition);
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
}
