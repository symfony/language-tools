<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;
use Symfony\Lsp\Index\SourceFactsOverlayOrder;

/** @extends AbstractSourceFactsIndex<MetadataSourceFacts> */
final class MetadataSourceIndex extends AbstractSourceFactsIndex
{
    public function __construct()
    {
        parent::__construct(SourceFactsOverlayOrder::PreserveSavedPosition);
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
}
