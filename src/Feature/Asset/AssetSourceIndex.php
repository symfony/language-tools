<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;
use Symfony\Lsp\Index\SourceFactsOverlayOrder;

/** @extends AbstractSourceFactsIndex<AssetSourceFacts> */
final class AssetSourceIndex extends AbstractSourceFactsIndex
{
    private bool $indexed = false;

    /** @var array<string, list<AssetSourceSymbol>> */
    private array $symbols = [];

    /** @var array<string, array<string, list<AssetSourceSymbol>>> */
    private array $symbolsByName = [];

    /** @var array<string, list<string>> */
    private array $declarationNames = [];

    public function __construct()
    {
        parent::__construct(SourceFactsOverlayOrder::PreserveSavedPosition);
    }

    /** @return list<AssetSourceSymbol> */
    public function symbols(AssetSymbolKind $kind, ?string $name = null): array
    {
        $this->index();

        return null === $name ? $this->symbols[$kind->value] ?? [] : $this->symbolsByName[$kind->value][$name] ?? [];
    }

    /** @return list<string> */
    public function declarationNames(AssetSymbolKind $kind): array
    {
        $this->index();

        return $this->declarationNames[$kind->value] ?? [];
    }

    protected function factsChanged(): void
    {
        $this->indexed = false;
    }

    private function index(): void
    {
        if ($this->indexed) {
            return;
        }

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
        $this->indexed = true;
    }
}
