<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<AssetSourceFacts> */
final class AssetSourceIndex extends AbstractSourceFactsIndex
{
    /** @var array<string, list<AssetSourceSymbol>> */
    private array $symbols = [];

    /** @var array<string, array<string, list<AssetSourceSymbol>>> */
    private array $symbolsByName = [];

    /** @var array<string, list<string>> */
    private array $declarationNames = [];

    /** @return list<AssetSourceSymbol> */
    public function symbols(AssetSymbolKind $kind, ?string $name = null): array
    {
        $this->derived();

        return null === $name ? $this->symbols[$kind->value] ?? [] : $this->symbolsByName[$kind->value][$name] ?? [];
    }

    /** @return list<string> */
    public function declarationNames(AssetSymbolKind $kind): array
    {
        $this->derived();

        return $this->declarationNames[$kind->value] ?? [];
    }

    protected function build(): void
    {
        $this->symbols = [];
        $this->symbolsByName = [];
        $declarationNames = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->symbols as $symbol) {
                $kind = $symbol->kind->value;
                $name = $symbol->name;
                $this->symbols[$kind][] = $symbol;
                $this->symbolsByName[$kind][$name][] = $symbol;
                if ($symbol->declaration) {
                    $declarationNames[$kind][$name] = true;
                }
            }
        }

        $this->declarationNames = [];
        foreach ($declarationNames as $kind => $names) {
            $this->declarationNames[$kind] = array_keys($names);
            sort($this->declarationNames[$kind]);
        }
    }
}
